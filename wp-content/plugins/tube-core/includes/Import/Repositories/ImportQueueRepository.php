<?php
/**
 * Data access for wp_tube_import_queue (ImportQueueRepositoryInterface) implementation.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Import\Repositories;

use RuntimeException;
use Tube_Core\Import\ImportStatus;

/**
 * Data access for wp_tube_import_queue (ImportQueueRepositoryInterface).
 *
 * Direct $wpdb access is the same documented, intentional exception
 * every dedicated-table repository in this project uses (ARCHITECTURE.md
 * §2.5/§11).
 *
 * `claim_batch()`'s "reclaim, then claim, then fetch what I claimed"
 * sequence identifies "what I claimed" by matching `claimed_at` against
 * the exact timestamp this call just wrote — accepted, bounded race:
 * MySQL `DATETIME` has one-second resolution, so two `import:process`
 * invocations claiming batches in the same wall-clock second could each
 * see the other's freshly-claimed rows in their own "what did I claim"
 * fetch. The realistic worst case is redundant reprocessing of a handful
 * of items, not corruption — `VideoImporter`'s Cloudflare-Stream-UID
 * duplicate check (ARCHITECTURE.md §12 Phase 5) already makes a second
 * processing attempt of the same item a safe no-op. The same category of
 * accepted tradeoff as `RedisViewCounter::flush()`'s overlapping-cron
 * handling in Phase 4, for the same reason: a fully race-proof claim
 * (e.g. `SELECT ... FOR UPDATE` inside an explicit transaction, or a
 * per-invocation claim-token column) is real added complexity this
 * phase's real-scale target — a bounded queue, processed by essentially
 * one cron invocation at a time — doesn't measurably benefit from.
 */
final class ImportQueueRepository implements ImportQueueRepositoryInterface
{
    /**
     * {@inheritDoc}
     *
     * @param list<array{source_key: string, payload: array<string, mixed>}> $items Items to enqueue.
     */
    public function bulk_enqueue(array $items): int
    {
        if ([] === $items) {
            return 0;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table        = $wpdb->prefix . 'tube_import_queue';
        $now          = esc_sql(current_time('mysql', true));
        $value_tuples = [];

        foreach ($items as $item) {
            $payload_json = wp_json_encode($item['payload']);

            // A JSON-encode failure (e.g. malformed UTF-8 in the source
            // data) means this one item can't be safely queued — skipped,
            // not allowed to corrupt the rest of the batch's SQL.
            if (false === $payload_json) {
                continue;
            }

            $source_key      = esc_sql($item['source_key']);
            $escaped_payload = esc_sql($payload_json);
            $value_tuples[]  = "('{$source_key}', '{$escaped_payload}', '{$now}', '{$now}')";
        }

        if ([] === $value_tuples) {
            return 0;
        }

        // Not built with $wpdb->prepare() -- same variable-length-VALUES
        // justification Tube_Core\Views\Repositories\VideoViewsRepository::bulk_record()
        // documents in full; every value here is esc_sql()'d.
        $sql = 'INSERT IGNORE INTO ' . $table . ' (source_key, payload, created_at, updated_at) VALUES '
            . implode(', ', $value_tuples);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); see the comment above $sql's assignment for why this isn't prepare()'d.
        $wpdb->query($sql);

        return (int) $wpdb->rows_affected;
    }

    /**
     * {@inheritDoc}
     *
     * @param int $limit               Maximum number of items to claim.
     * @param int $stale_after_seconds How long an item may sit in `processing` before being reclaimed.
     *
     * @return list<array{id: int, source_key: string, payload: array<string, mixed>, attempts: int, max_attempts: int}>
     *
     * @throws RuntimeException If a query template is malformed (a bug in this method, not in any argument).
     */
    public function claim_batch(int $limit, int $stale_after_seconds): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table        = $wpdb->prefix . 'tube_import_queue';
        $now          = current_time('mysql', true);
        $stale_cutoff = gmdate('Y-m-d H:i:s', time() - $stale_after_seconds);

        // Resume: reclaim anything abandoned mid-processing.
        $reclaim_sql = $wpdb->prepare(
            "UPDATE %i SET status = 'pending', claimed_at = NULL, updated_at = %s"
            . " WHERE status = 'processing' AND claimed_at < %s",
            $table,
            $now,
            $stale_cutoff
        );

        // wpdb::prepare() returns null only for a malformed literal format
        // string above (a bug in this method, not something $table/$now/
        // $stale_cutoff could trigger) — guarded rather than asserted away,
        // since wpdb::query() itself is typed to reject null (the same
        // pattern VideoViewsRepository::purge_before() already uses).
        if (null === $reclaim_sql) {
            throw new RuntimeException('wpdb::prepare() returned null for the reclaim query in ' . self::class . '.');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $reclaim_sql *is* $wpdb->prepare()'d above -- the sniff's pattern-matching doesn't follow it through the null-guard branch above (same as VideoViewsRepository::purge_before()).
        $wpdb->query($reclaim_sql);

        // Claim: atomically mark up to $limit pending rows as processing.
        $claim_sql = $wpdb->prepare(
            "UPDATE %i SET status = 'processing', claimed_at = %s, updated_at = %s"
            . ' WHERE status = %s ORDER BY id ASC LIMIT %d',
            $table,
            $now,
            $now,
            ImportStatus::Pending->value,
            $limit
        );

        if (null === $claim_sql) {
            throw new RuntimeException('wpdb::prepare() returned null for the claim query in ' . self::class . '.');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $claim_sql *is* $wpdb->prepare()'d above -- the sniff's pattern-matching doesn't follow it through the null-guard branch above (same as VideoViewsRepository::purge_before()).
        $wpdb->query($claim_sql);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, source_key, payload, attempts, max_attempts FROM %i'
                . ' WHERE status = %s AND claimed_at = %s ORDER BY id ASC',
                $table,
                ImportStatus::Processing->value,
                $now
            ),
            ARRAY_A
        );

        // Same documented wordpress-stubs gap as VideoViewsRepository::window_sums().
        /** @var array<int, array{id: string, source_key: string, payload: string, attempts: string, max_attempts: string}> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $rows = (array) $rows;

        $result = [];

        foreach ($rows as $row) {
            $decoded = json_decode($row['payload'], true);

            // Defensive: every payload here was written by bulk_enqueue()
            // as valid JSON; a non-array decode result would mean the
            // stored data was corrupted after the fact, not a case this
            // repository's own writes could ever produce.
            if (! is_array($decoded)) {
                $decoded = [];
            }

            // Re-keyed to string for the same reason
            // ImportCommand::enqueue() does: json_decode(..., true) can
            // produce int keys for any all-digit JSON object key.
            $payload = [];

            foreach ($decoded as $key => $value) {
                $payload[ (string) $key ] = $value;
            }

            $result[] = [
                'id'           => (int) $row['id'],
                'source_key'   => $row['source_key'],
                'payload'      => $payload,
                'attempts'     => (int) $row['attempts'],
                'max_attempts' => (int) $row['max_attempts'],
            ];
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param int $id       The queue item's ID.
     * @param int $video_id The video it produced.
     */
    public function mark_completed(int $id, int $video_id): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->update(
            $wpdb->prefix . 'tube_import_queue',
            [
                'status'     => ImportStatus::Completed->value,
                'video_id'   => $video_id,
                'claimed_at' => null,
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param int    $id            The queue item's ID.
     * @param string $error_message What went wrong, stored for operator visibility.
     *
     * @throws RuntimeException If the query template is malformed (a bug in this method, not in any argument).
     */
    public function mark_failed_or_retry(int $id, string $error_message): ImportStatus
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_import_queue';
        $now   = current_time('mysql', true);

        $update_sql = $wpdb->prepare(
            'UPDATE %i SET attempts = attempts + 1, last_error = %s,'
            . ' status = CASE WHEN (attempts + 1) >= max_attempts THEN %s ELSE %s END,'
            . ' claimed_at = NULL, updated_at = %s WHERE id = %d',
            $table,
            $error_message,
            ImportStatus::Failed->value,
            ImportStatus::Pending->value,
            $now,
            $id
        );

        // See claim_batch()'s identical null-guard comment for why.
        if (null === $update_sql) {
            throw new RuntimeException(
                'wpdb::prepare() returned null for the retry/fail query in ' . self::class . '.'
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $update_sql *is* $wpdb->prepare()'d above -- the sniff's pattern-matching doesn't follow it through the null-guard branch above (same as VideoViewsRepository::purge_before()).
        $wpdb->query($update_sql);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE id = %d', $table, $id));
        $status = (string) $status;

        return ImportStatus::from($status);
    }

    /**
     * {@inheritDoc}
     *
     * @return array<string, int> Status value => count.
     */
    public function status_counts(): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT status, COUNT(*) AS item_count FROM %i GROUP BY status',
                $wpdb->prefix . 'tube_import_queue'
            ),
            ARRAY_A
        );

        /** @var array<int, array{status: string, item_count: string}> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $rows = (array) $rows;

        $result = [];

        foreach ($rows as $row) {
            $result[ $row['status'] ] = (int) $row['item_count'];
        }

        return $result;
    }
}
