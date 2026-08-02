<?php
/**
 * Data access for wp_tube_video_views (VideoViewsRepositoryInterface).
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Views\Repositories;

/**
 * Data access for wp_tube_video_views (VideoViewsRepositoryInterface).
 *
 * None of these tables have a WP_Query/WP_Meta_Query equivalent, so
 * direct $wpdb access here is the documented, intentional exception
 * described in ARCHITECTURE.md §2.5/§11 — the same pattern
 * SchemaVersionStore and every migration already use.
 */
final class VideoViewsRepository implements VideoViewsRepositoryInterface
{
    /**
     * {@inheritDoc}
     *
     * @param array<int, int> $counts    Video ID => view count to add, for this one hour bucket.
     * @param string          $view_hour MySQL `DATETIME` string truncated to the hour.
     */
    public function bulk_record(array $counts, string $view_hour): void
    {
        if ([] === $counts) {
            return;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table        = $wpdb->prefix . 'tube_video_views';
        $quoted_hour  = "'" . esc_sql($view_hour) . "'";
        $value_tuples = [];

        foreach ($counts as $video_id => $count) {
            $value_tuples[] = sprintf('(%d, %s, %d)', $video_id, $quoted_hour, $count);
        }

        // Not built with $wpdb->prepare(): a multi-row VALUES clause is
        // inherently variable-length, which prepare()'s literal-string
        // requirement can never accept regardless of how the query is
        // actually built (confirmed empirically, not assumed). Every
        // value here is either a PHP int (sprintf %d forces numeric
        // coercion, the same protection prepare()'s %d gives) or passed
        // through esc_sql() ($view_hour) -- no unescaped/unvalidated
        // value reaches this query. Same class of justification
        // AbstractMigration::drop_table()/drop_index() already use for
        // identifiers prepare() can't parameterize either.
        $sql = 'INSERT INTO ' . $table . ' (video_id, view_hour, view_count) VALUES '
            . implode(', ', $value_tuples)
            . ' ON DUPLICATE KEY UPDATE view_count = view_count + VALUES(view_count)';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- see the comment above $sql's assignment.
        $wpdb->query($sql);
    }

    /**
     * {@inheritDoc}
     *
     * @param string $today_start     MySQL `DATETIME` string: start of "today" (inclusive).
     * @param string $seven_days_ago  MySQL `DATETIME` string: seven-day window start (inclusive).
     * @param string $thirty_days_ago MySQL `DATETIME` string: thirty-day window start (inclusive).
     *
     * @return array<int, array{today: int, d7: int, d30: int}> Video ID => window sums.
     */
    public function window_sums(string $today_start, string $seven_days_ago, string $thirty_days_ago): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_video_views';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT video_id,'
                . ' SUM(CASE WHEN view_hour >= %s THEN view_count ELSE 0 END) AS today,'
                . ' SUM(CASE WHEN view_hour >= %s THEN view_count ELSE 0 END) AS d7,'
                . ' SUM(CASE WHEN view_hour >= %s THEN view_count ELSE 0 END) AS d30'
                . ' FROM %i'
                . ' WHERE view_hour >= %s'
                . ' GROUP BY video_id',
                $today_start,
                $seven_days_ago,
                $thirty_days_ago,
                $table,
                $thirty_days_ago
            ),
            ARRAY_A
        );

        // wordpress-stubs types wpdb::get_results() as `array|object|null`
        // regardless of $output_type, the same documented stub gap
        // SchemaVersionStore::applied_versions() already works around.
        /** @var array<int, array{video_id: string, today: string, d7: string, d30: string}> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $rows = (array) $rows;

        $result = [];

        foreach ($rows as $row) {
            $result[ (int) $row['video_id'] ] = [
                'today' => (int) $row['today'],
                'd7'    => (int) $row['d7'],
                'd30'   => (int) $row['d30'],
            ];
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $cutoff MySQL `DATETIME` string.
     */
    public function purge_before(string $cutoff): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_video_views';
        $sql   = $wpdb->prepare('DELETE FROM %i WHERE view_hour < %s', $table, $cutoff);

        // wpdb::prepare() returns null only for a malformed format string
        // (a bug in the literal SQL above, not something $cutoff/$table
        // could ever trigger) — guarded rather than asserted away, since
        // wpdb::query() itself is typed to reject null.
        if (null === $sql) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d two lines above -- the sniff's pattern-matching doesn't follow it through the null-guard branch above.
        $deleted = $wpdb->query($sql);

        return (int) $deleted;
    }
}
