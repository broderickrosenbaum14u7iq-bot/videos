<?php
/**
 * Tube Theme's bootstrap.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const TUBE_THEME_VERSION = '1.0.0';

require_once __DIR__ . '/inc/template-functions.php';

/**
 * Theme setup. Deliberately does NOT add `title-tag` support:
 * `tube_seo_head()` (tube-seo, Phase 8) echoes its own `<title>` tag
 * directly inside every template's `<head>` — declaring `title-tag`
 * support would make WordPress core also auto-inject one via `wp_head()`,
 * producing two `<title>` tags.
 */
add_action(
    'after_setup_theme',
    static function (): void {
        add_theme_support('post-thumbnails');
        add_theme_support('html5', ['search-form', 'script', 'style']);
    }
);

add_action(
    'wp_enqueue_scripts',
    static function (): void {
        wp_enqueue_style(
            'tube-theme',
            get_stylesheet_directory_uri() . '/assets/css/tube-theme.css',
            [],
            TUBE_THEME_VERSION
        );

        wp_enqueue_script(
            'tube-theme',
            get_stylesheet_directory_uri() . '/assets/js/tube-theme.js',
            [],
            TUBE_THEME_VERSION,
            true
        );
    }
);
