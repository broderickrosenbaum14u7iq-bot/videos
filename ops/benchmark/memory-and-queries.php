<?php
/**
 * Benchmark: memory, execution time, and SQL query count for a real
 * tube-core operation (MigrationRunner::status(), the same logic
 * `wp tube migrate status` runs).
 *
 * Run via: wp eval-file ops/benchmark/memory-and-queries.php --allow-root
 *
 * $wpdb->num_queries is incremented unconditionally by wpdb::query(),
 * independent of SAVEQUERIES (which only gates the *detailed* query
 * log) — so query count is measurable here without changing any PHP
 * config in the staging environment.
 *
 * No `declare(strict_types=1)` here: confirmed empirically (not
 * assumed) that `wp eval-file` executes the target file's contents via
 * PHP's `eval()`, and `strict_types` cannot be declared inside `eval()`'d
 * code — a PHP language restriction, not something this project's
 * coding standard can override. This file is ops tooling, outside every
 * plugin's autoloaded, phpcs-scanned codebase (`phpcs.xml` only scans
 * `wp-content/plugins` and `wp-content/themes/tube-theme`); the
 * project-wide strict-types rule (`DEVELOPMENT_RULES.md` §2) governs
 * that code, not this.
 */

global $wpdb;

$queries_before = $wpdb->num_queries;
$start = microtime(true);

$status = \Tube_Core\Plugin::instance()->migration_runner()->status();

$elapsed_ms = (microtime(true) - $start) * 1000;
$queries = $wpdb->num_queries - $queries_before;
$peak_memory_mb = memory_get_peak_usage(true) / 1024 / 1024;

echo wp_json_encode(
    [
        'operation' => 'MigrationRunner::status()',
        'rows_returned' => count($status),
        'execution_time_ms' => round($elapsed_ms, 3),
        'sql_queries' => $queries,
        'peak_memory_mb' => round($peak_memory_mb, 3),
    ]
) . PHP_EOL;
