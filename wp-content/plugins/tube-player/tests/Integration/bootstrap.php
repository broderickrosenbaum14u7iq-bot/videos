<?php
/**
 * Bootstrap for the integration test suite: loads a real WordPress
 * install (both tube-core and tube-player active, per `Requires
 * Plugins`) so tests can exercise the real `Tube_Core\Plugin` read path
 * and real `esc_url()`/`esc_attr()` output. Must run inside the wpcli
 * Docker container — see tube-core's own tests/Integration/bootstrap.php
 * for the identical reasoning (this file mirrors it exactly; the
 * bootstrap concern is per-container, not per-plugin, so there is
 * nothing plugin-specific to justify a different shape here).
 *
 * @package Tube_Player
 */

declare(strict_types=1);

(static function (): void {
    $wp_load_path = getenv('TUBE_PLAYER_WP_LOAD_PATH');

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
            "(set TUBE_PLAYER_WP_LOAD_PATH to override the expected wp-load.php path)\n"
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
