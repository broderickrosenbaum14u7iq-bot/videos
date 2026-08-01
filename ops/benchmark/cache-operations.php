<?php
/**
 * Benchmark: cache hits/misses (Tube_Cache\Plugin::cache(), the real
 * Redis-backed CacheInterface) and the execution cost of the operations
 * that produce them.
 *
 * Run via: wp eval-file ops/benchmark/cache-operations.php --allow-root
 *
 * Redis's own keyspace_hits/keyspace_misses counters (read via `redis-cli
 * INFO stats` in run.sh, before and after this script) are the
 * authoritative source for the hit/miss numbers themselves — this script
 * only needs to generate a real, known mix of hits and misses against the
 * real Cache API for that INFO delta to be meaningful, and times its own
 * operations as a bonus data point.
 *
 * No `declare(strict_types=1)` here — see memory-and-queries.php's
 * docblock for why: `wp eval-file` runs its target through PHP's
 * `eval()`, which cannot contain a `strict_types` declaration at all.
 */

const ITERATIONS = 1000;

$cache = \Tube_Cache\Plugin::instance()->cache();

$start = microtime(true);

for ($i = 0; $i < ITERATIONS; $i++) {
    $cache->set("benchmark:cache-operations:{$i}", ['n' => $i], 60);
}

$set_elapsed_ms = (microtime(true) - $start) * 1000;

$start = microtime(true);

// A real hit for every key just set above.
for ($i = 0; $i < ITERATIONS; $i++) {
    $cache->get("benchmark:cache-operations:{$i}");
}

$hit_elapsed_ms = (microtime(true) - $start) * 1000;

$start = microtime(true);

// A real miss: this key was never set.
for ($i = 0; $i < ITERATIONS; $i++) {
    $cache->get("benchmark:cache-operations:miss:{$i}");
}

$miss_elapsed_ms = (microtime(true) - $start) * 1000;

// Clean up what this benchmark run wrote, so repeated runs measure the
// same starting state and don't accumulate keys in the staging Redis.
for ($i = 0; $i < ITERATIONS; $i++) {
    $cache->delete("benchmark:cache-operations:{$i}");
}

echo wp_json_encode(
    [
        'operation' => 'CacheInterface::set()/get()/delete() against real Redis',
        'iterations' => ITERATIONS,
        'set_total_time_ms' => round($set_elapsed_ms, 3),
        'set_avg_time_ms' => round($set_elapsed_ms / ITERATIONS, 5),
        'get_hit_total_time_ms' => round($hit_elapsed_ms, 3),
        'get_hit_avg_time_ms' => round($hit_elapsed_ms / ITERATIONS, 5),
        'get_miss_total_time_ms' => round($miss_elapsed_ms, 3),
        'get_miss_avg_time_ms' => round($miss_elapsed_ms / ITERATIONS, 5),
    ]
) . PHP_EOL;
