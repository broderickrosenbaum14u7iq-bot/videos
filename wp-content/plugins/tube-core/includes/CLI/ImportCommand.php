<?php
/**
 * `wp tube-core import:enqueue` / `import:process` / `import:status`.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\CLI;

use Tube_Core\Import\BatchProcessor;
use Tube_Core\Import\Repositories\ImportQueueRepositoryInterface;
use WP_CLI;

use function WP_CLI\Utils\format_items;

/**
 * `wp tube-core import:enqueue` / `import:process` / `import:status` —
 * the WP-CLI surface for ARCHITECTURE.md §12 Phase 5's bulk-import
 * pipeline, sized for 10,000+ videos on a single VPS: `import:process`
 * is meant to be run continuously (or invoked repeatedly in a tight
 * loop) for an initial bulk backfill, then falls back to its normal
 * every-minute cron cadence (ARCHITECTURE.md §7) for steady-state trickle
 * imports.
 */
final class ImportCommand
{
    /**
     * `import:process`'s default batch size when `--limit` isn't given —
     * small enough that one run stays fast even on modest VPS hardware,
     * large enough that a 10,000-item backfill completes in a practical
     * number of invocations when run in a tight loop.
     */
    private const DEFAULT_BATCH_LIMIT = 50;

    /**
     * Construct the command around the queue it reports on and the processor it drives.
     *
     * @param ImportQueueRepositoryInterface $queue_repository Enqueued to, reported on.
     * @param BatchProcessor                 $processor        Drives `import:process`.
     */
    public function __construct(
        private readonly ImportQueueRepositoryInterface $queue_repository,
        private readonly BatchProcessor $processor
    ) {
    }

    /**
     * Enqueue items from a JSON file.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to a JSON file: an array of
     *   `{"source_key": "...", "payload": {"title": "...", "cf_stream_uid": "...",
     *   "category_slugs": [...], "tag_slugs": [...]}}` objects. `source_key`
     *   must be unique per source item — re-running against the same file,
     *   or a file with overlapping items, is safe (duplicates are skipped,
     *   not re-queued).
     *
     * ## EXAMPLES
     *
     *     wp tube-core import:enqueue /path/to/videos.json
     *
     * @when after_wp_load
     *
     * @param array<int, string> $args Positional arguments: [0] => the file path.
     */
    public function enqueue(array $args): void
    {
        $file = $args[0];

        if (! is_readable($file)) {
            WP_CLI::error("Cannot read file: {$file}");

            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file path from a WP-CLI operator's own filesystem, not a remote URL; wp_remote_get() is for HTTP requests, not applicable here.
        $contents = file_get_contents($file);

        if (false === $contents) {
            WP_CLI::error("Failed to read file: {$file}");

            return;
        }

        $items = json_decode($contents, true);

        if (! is_array($items)) {
            WP_CLI::error('File does not contain a JSON array.');

            return;
        }

        $valid_items = [];
        $skipped     = 0;

        foreach ($items as $item) {
            if (
                ! is_array($item)
                || ! isset($item['source_key'], $item['payload'])
                || ! is_string($item['source_key'])
                || '' === $item['source_key']
                || ! is_array($item['payload'])
            ) {
                ++$skipped;

                continue;
            }

            // json_decode(..., true) can produce int keys for any
            // all-digit JSON object key; re-keyed to string here so the
            // result genuinely matches array<string, mixed>, not just by
            // convention -- a real JSON payload's field names (title,
            // cf_stream_uid, ...) are never all-digit, but this makes
            // that guarantee explicit rather than assumed.
            $payload = [];

            foreach ($item['payload'] as $key => $value) {
                $payload[ (string) $key ] = $value;
            }

            $valid_items[] = [
                'source_key' => $item['source_key'],
                'payload'    => $payload,
            ];
        }

        if ($skipped > 0) {
            WP_CLI::warning("Skipped {$skipped} malformed item(s) (missing/invalid \"source_key\" or \"payload\").");
        }

        $inserted   = $this->queue_repository->bulk_enqueue($valid_items);
        $duplicates = count($valid_items) - $inserted;

        WP_CLI::success("Enqueued {$inserted} item(s) ({$duplicates} already queued, skipped as duplicates).");
    }

    /**
     * Claim and process a batch of pending items.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Maximum number of items to claim this run. Default: 50.
     *
     * ## EXAMPLES
     *
     *     wp tube-core import:process
     *     wp tube-core import:process --limit=200
     *
     * @when after_wp_load
     *
     * @param array<int, string>    $args       Positional arguments (unused).
     * @param array<string, string> $assoc_args Associative arguments (--limit).
     */
    public function process(array $args, array $assoc_args): void
    {
        $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : self::DEFAULT_BATCH_LIMIT;

        if ($limit < 1) {
            WP_CLI::error('--limit must be a positive integer.');

            return;
        }

        $result = $this->processor->process($limit);

        if (0 === $result['completed'] + $result['retried'] + $result['failed']) {
            WP_CLI::log('Nothing pending to process.');

            return;
        }

        WP_CLI::success(
            sprintf(
                'Processed batch: %d completed, %d retried, %d permanently failed.',
                $result['completed'],
                $result['retried'],
                $result['failed']
            )
        );
    }

    /**
     * Show queue item counts by status.
     *
     * ## EXAMPLES
     *
     *     wp tube-core import:status
     *
     * @when after_wp_load
     */
    public function status(): void
    {
        $counts = $this->queue_repository->status_counts();

        if ([] === $counts) {
            WP_CLI::log('The import queue is empty.');

            return;
        }

        $rows = [];

        foreach ($counts as $status => $count) {
            $rows[] = [
                'status' => $status,
                'count'  => $count,
            ];
        }

        format_items('table', $rows, ['status', 'count']);
    }
}
