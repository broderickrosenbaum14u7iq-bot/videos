<?php
/**
 * Benchmark: import throughput — items/second through the real
 * ImportQueueRepository + VideoImporter + BatchProcessor pipeline
 * (Phase 5), against real MySQL and real wp_insert_post().
 *
 * Run via: wp eval-file ops/benchmark/import-throughput.php --allow-root
 *
 * Enqueues ITEM_COUNT synthetic items under a unique source_key prefix,
 * times a single BatchProcessor::process() run against all of them, then
 * deletes everything it created (queue rows, video posts, metadata rows)
 * — this script leaves no residue in the database, the same discipline
 * every other live/benchmark verification in this project follows.
 *
 * No `declare(strict_types=1)` here — see memory-and-queries.php's
 * docblock for why: `wp eval-file` runs its target through PHP's
 * `eval()`, which cannot contain a `strict_types` declaration at all.
 * This file is ops tooling outside every plugin's codebase, not subject
 * to the project-wide strict-types rule the same way plugin code is.
 */

const ITEM_COUNT = 200;

$queue_repository    = new \Tube_Core\Import\Repositories\ImportQueueRepository();
$metadata_repository = new \Tube_Core\Video\Repositories\VideoMetadataRepository();
$importer            = new \Tube_Core\Import\VideoImporter($metadata_repository);
$processor           = new \Tube_Core\Import\BatchProcessor(
    $queue_repository,
    $importer,
    \Tube_Core\Plugin::instance()->events()
);

$prefix = 'bench-import-' . uniqid('', true) . '-';
$items  = [];

for ($i = 0; $i < ITEM_COUNT; $i++) {
    $items[] = [
        'source_key' => $prefix . $i,
        'payload'    => [
            'title'         => "Benchmark Video {$i}",
            'cf_stream_uid' => $prefix . 'uid-' . $i,
        ],
    ];
}

$queue_repository->bulk_enqueue($items);

$start  = microtime(true);
$result = $processor->process(ITEM_COUNT);
$elapsed_s = microtime(true) - $start;

$items_per_second = $elapsed_s > 0 ? ITEM_COUNT / $elapsed_s : 0;

echo wp_json_encode(
    [
        'operation' => 'BatchProcessor::process() (import pipeline)',
        'item_count' => ITEM_COUNT,
        'completed' => $result['completed'],
        'retried' => $result['retried'],
        'failed' => $result['failed'],
        'total_time_ms' => round($elapsed_s * 1000, 3),
        'items_per_second' => round($items_per_second, 2),
    ]
) . PHP_EOL;

// Cleanup: this benchmark's own rows/posts only, by prefix — never a
// blanket TRUNCATE, so it can never touch real data in this table.
global $wpdb;

$wpdb->query(
    $wpdb->prepare(
        'DELETE FROM %i WHERE source_key LIKE %s',
        $wpdb->prefix . 'tube_import_queue',
        $wpdb->esc_like($prefix) . '%'
    )
);

$video_ids = $wpdb->get_col(
    $wpdb->prepare(
        'SELECT video_id FROM %i WHERE cf_stream_uid LIKE %s',
        $wpdb->prefix . 'tube_video_metadata',
        $wpdb->esc_like($prefix) . '%'
    )
);

foreach ($video_ids as $video_id) {
    $wpdb->query(
        $wpdb->prepare(
            'DELETE FROM %i WHERE video_id = %d',
            $wpdb->prefix . 'tube_video_metadata',
            (int) $video_id
        )
    );

    wp_delete_post((int) $video_id, true);
}
