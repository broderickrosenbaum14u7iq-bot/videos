<?php
/**
 * Bootstrap for the integration test suite: loads a real WordPress
 * install (tube-core, tube-cache, and tube-search all active, per
 * `Requires Plugins`) so tests can exercise the real index-sync event
 * wiring, real MySQL FULLTEXT search, and real Redis-backed cache
 * invalidation. Must run inside the wpcli Docker container — mirrors
 * tube-core's/tube-player's own tests/Integration/bootstrap.php exactly;
 * the bootstrap concern is per-container, not per-plugin.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

(static function (): void {
    $wp_load_path = getenv('TUBE_SEARCH_WP_LOAD_PATH');

    if (false === $wp_load_path || '' === $wp_load_path) {
        $wp_load_path = '/var/www/html/wp-load.php';
    }

    if (! is_readable($wp_load_path)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress (and therefore WP_Filesystem) has not been loaded at this point; this is the exact failure case where it never will be.
        fwrite(
            STDERR,
            "Cannot read wp-load.php at {$wp_load_path}. The integration suite requires a real WordPress " .
            "install and must be run inside the wpcli Docker container, e.g.:\n" .
            "  docker compose exec wpcli vendor/bin/phpunit -c phpunit-integration.xml.dist\n" .
            "(set TUBE_SEARCH_WP_LOAD_PATH to override the expected wp-load.php path)\n"
        );
        exit(1);
    }

    $_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI'] ?? '/';
    $_SERVER['SERVER_NAME']    = $_SERVER['SERVER_NAME'] ?? 'localhost';

    require_once $wp_load_path;
})();

require_once __DIR__ . '/../../vendor/autoload.php';
