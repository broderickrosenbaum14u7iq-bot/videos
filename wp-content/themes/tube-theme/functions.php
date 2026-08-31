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

const TUBE_THEME_VERSION = '1.5.0';

require_once __DIR__ . '/inc/template-functions.php';
require_once __DIR__ . '/inc/customizer.php';

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
        // 2026-08-28: header brand logo switcher -- native Customizer
        // Logo control under Site Identity (`get_theme_mod('custom_logo')`),
        // not a bespoke raw-URL field. `flex-height`/`flex-width` let an
        // admin upload any real logo aspect ratio; `tube_theme_render_site_brand()`
        // (inc/template-functions.php) is what actually caps the rendered
        // <img> size in the header slot via CSS max-height/max-width.
        add_theme_support(
            'custom-logo',
            [
                'height'      => 40,
                'width'       => 200,
                'flex-height' => true,
                'flex-width'  => true,
            ]
        );
        tube_theme_register_footer_menus();
    }
);

add_action(
    'wp_enqueue_scripts',
    static function (): void {
        wp_enqueue_style(
            'tube-theme',
            get_stylesheet_directory_uri() . '/assets/css/tube-theme.css',
            [],
            tube_theme_asset_version('assets/css/tube-theme.css')
        );

        // Per-site visual identity layer (multi-site hosting, `TUBE_THEME_SITE_BRAND`
        // constant): loaded ONLY for a site that opts in, on top of the shared
        // stylesheet above, so every other site's output is byte-for-byte
        // unaffected. See tube_theme_site_brand()'s own docblock.
        $brand = tube_theme_site_brand();

        if ('default' !== $brand) {
            $brand_stylesheet = 'assets/css/site-' . $brand . '.css';

            if (file_exists(get_stylesheet_directory() . '/' . $brand_stylesheet)) {
                wp_enqueue_style(
                    'tube-theme-brand-' . $brand,
                    get_stylesheet_directory_uri() . '/' . $brand_stylesheet,
                    ['tube-theme'],
                    tube_theme_asset_version($brand_stylesheet)
                );
            }
        }

        wp_enqueue_script(
            'tube-theme',
            get_stylesheet_directory_uri() . '/assets/js/tube-theme.js',
            [],
            tube_theme_asset_version('assets/js/tube-theme.js'),
            true
        );

        wp_localize_script(
            'tube-theme',
            'tubeThemeI18n',
            [
                'loadingMore'   => __('Loading more videos…', 'tube-theme'),
                'loadMoreError' => __('Couldn\'t load more videos. Use the pagination below instead.', 'tube-theme'),
            ]
        );
    }
);

add_filter(
    'body_class',
    static function (array $classes): array {
        $classes[] = 'site-brand-' . tube_theme_site_brand();

        return $classes;
    }
);
