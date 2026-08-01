<?php
/**
 * Benchmark: event dispatch cost, via the real Dispatcher and the real
 * WordPressHookBus — not a synthetic/mocked version.
 *
 * Run via: wp eval-file ops/benchmark/event-dispatch.php --allow-root
 *
 * Dispatches VIDEO_UPDATED (chosen because it has no destructive side
 * effect — no listener currently touches the database in response to
 * it) 1,000 times and reports total and average cost. This measures the
 * dispatcher's own overhead (catalog validation + do_action), not
 * listener execution cost — no other plugin has registered a listener
 * yet as of Phase 2, so there is nothing else to measure.
 *
 * No `declare(strict_types=1)` here — see memory-and-queries.php's
 * docblock for why: `wp eval-file` runs its target through PHP's
 * `eval()`, which cannot contain a `strict_types` declaration at all.
 * This file is ops tooling outside every plugin's codebase, not subject
 * to the project-wide strict-types rule the same way plugin code is.
 */

const ITERATIONS = 1000;

$events = \Tube_Core\Plugin::instance()->events();

$start = microtime(true);

for ($i = 0; $i < ITERATIONS; $i++) {
    $events->dispatch(\Tube_Core\Events\EventCatalog::VIDEO_UPDATED, ['video_id' => 1]);
}

$elapsed_ms = (microtime(true) - $start) * 1000;

echo wp_json_encode(
    [
        'operation' => 'Dispatcher::dispatch(VIDEO_UPDATED)',
        'iterations' => ITERATIONS,
        'total_time_ms' => round($elapsed_ms, 3),
        'avg_time_per_dispatch_ms' => round($elapsed_ms / ITERATIONS, 5),
    ]
) . PHP_EOL;
