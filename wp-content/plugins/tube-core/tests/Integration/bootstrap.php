<?php
/**
 * Bootstrap for the integration test suite: loads a real WordPress
 * install (not a WP_UnitTestCase sandbox — see PHASE-5.md for why that
 * was not set up) so tests can exercise real $wpdb, real Redis, and real
 * WordPress core functions. Must run inside the wpcli Docker container,
 * which is the only place a full WordPress + MySQL + Redis stack is
 * reachable — see ops/README or docker-compose.yml's `wpcli` service.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

// Wrapped in an IIFE so $wp_load_path stays a local rather than a bare
// global (WordPress.NamingConventions.PrefixAllGlobals) -- this file is a
// plain bootstrap script, not a class, so a function scope is the only
// way to avoid that without a throwaway prefixed name.
(static function (): void {
    $wp_load_path = getenv('TUBE_CORE_WP_LOAD_PATH');

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
            "(set TUBE_CORE_WP_LOAD_PATH to override the expected wp-load.php path)\n"
        );
        exit(1);
    }

    // wp-load.php assumes an HTTP request context. WP-CLI stubs the same
    // $_SERVER keys internally before bootstrapping WordPress from a bare
    // CLI process; do the same here so plugins/WordPress core that read
    // these superglobals during init do not emit undefined-index notices.
    $_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI'] ?? '/';
    $_SERVER['SERVER_NAME']    = $_SERVER['SERVER_NAME'] ?? 'localhost';

    require_once $wp_load_path;
})();

// wp-load.php only loads wp-admin/includes/user.php when running inside
// wp-admin itself; wp_delete_user() (and friends) live there and are
// needed by tests that create/clean up real WP_User accounts.
require_once ABSPATH . 'wp-admin/includes/user.php';

require_once __DIR__ . '/../../vendor/autoload.php';
