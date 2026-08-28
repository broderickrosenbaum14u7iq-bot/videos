<?php
/**
 * "Tube SEO -> Homepage SEO" admin settings screen.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Admin;

/**
 * Explicit, per-site-configurable Homepage SEO Title/Meta Description —
 * 2026-08-26 addition. Every other page type's title/description is
 * already derived from real content (a video's title/excerpt, a term's
 * name/description); the homepage has no such content of its own to
 * derive from, and this project is deliberately going to be cloned into
 * several independent sites (see this class's own reasoning throughout),
 * each wanting its own homepage-targeted keyword without a code change
 * or redeploy — so, uniquely for the homepage, this small
 * WordPress-Settings-API-backed admin screen exists to let an operator
 * set that value directly, in wp-admin, per site.
 *
 * `Tube_Seo\Head\SeoHead::resolve()`'s `is_front_page()` branch is the
 * only reader of the two options this screen writes
 * (`self::OPTION_TITLE`/`self::OPTION_DESCRIPTION`) — see its own
 * docblock comment for the exact fallback hierarchy. Both stay `''`
 * (WordPress's own `register_setting()` default) until an operator
 * explicitly sets one, so a fresh clone of this project — or the
 * current site, before this feature existed — behaves identically to
 * before this class existed: SeoHead already treated `''` as "nothing
 * configured, fall back" for every other value it reads this same way.
 *
 * Deliberately NOT a general "SEO settings" framework: two fields, one
 * page, no tabs/multi-section scaffolding — this plugin has zero other
 * settings needing a home, and building one would be speculative
 * complexity for a single-purpose need per this project's own
 * anti-over-engineering convention (see e.g. `SitemapGenerator`'s own
 * docblock for the same reasoning applied elsewhere in this plugin).
 */
final class HomepageSeoSettings
{
    /**
     * The option the Homepage SEO Title is stored under. Read by
     * `Tube_Seo\Head\SeoHead::homepage_custom_title()`.
     */
    public const OPTION_TITLE = 'tube_seo_home_title';

    /**
     * The option the Homepage Meta Description is stored under. Read by
     * `Tube_Seo\Head\SeoHead::homepage_custom_description()`.
     */
    public const OPTION_DESCRIPTION = 'tube_seo_home_description';

    /**
     * The recommended (not enforced — guidance only) SEO title length range.
     */
    private const TITLE_RECOMMENDED_MIN = 50;
    private const TITLE_RECOMMENDED_MAX = 60;

    /**
     * The recommended (not enforced — guidance only) meta description length range.
     */
    private const DESCRIPTION_RECOMMENDED_MIN = 140;
    private const DESCRIPTION_RECOMMENDED_MAX = 160;

    /**
     * The `register_setting()` option group both fields share.
     */
    private const OPTION_GROUP = 'tube_seo_homepage_seo';

    /**
     * The settings section both fields render into.
     */
    private const SECTION = 'tube_seo_homepage_seo_main';

    /**
     * This screen's (and its parent top-level menu's) slug.
     */
    public const PAGE_SLUG = 'tube-seo-homepage';

    /**
     * The capability required to view or change these settings — the
     * same one WordPress's own core "Reading"/"General" settings screens
     * require, and every editable tube-admin screen already uses.
     */
    private const CAPABILITY = 'manage_options';

    /**
     * Register the "Tube SEO" top-level menu and its one "Homepage SEO" page.
     * Called on `admin_menu`.
     */
    public function register_menu(): void
    {
        add_menu_page(
            __('Tube SEO', 'tube-seo'),
            __('Tube SEO', 'tube-seo'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render'],
            'dashicons-search',
            80
        );

        add_submenu_page(
            self::PAGE_SLUG,
            __('Homepage SEO', 'tube-seo'),
            __('Homepage SEO', 'tube-seo'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    /**
     * Register both options (with real sanitize callbacks) and the one
     * section/its two fields — the WordPress Settings API plumbing that
     * gives this screen its nonce (`settings_fields()`), capability
     * check (`options.php` itself verifies `manage_options` before
     * writing any `option_page` it doesn't recognize as the current
     * user's to change), and sanitization (registered here, run by
     * `options.php` before either option is ever written). Called on `admin_init`.
     */
    public function register_settings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_TITLE,
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            self::OPTION_DESCRIPTION,
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
                'default'           => '',
            ]
        );

        add_settings_section(
            self::SECTION,
            '',
            static function (): void {
            },
            self::PAGE_SLUG
        );

        add_settings_field(
            self::OPTION_TITLE,
            __('Homepage SEO Title', 'tube-seo'),
            [$this, 'render_title_field'],
            self::PAGE_SLUG,
            self::SECTION
        );

        add_settings_field(
            self::OPTION_DESCRIPTION,
            __('Homepage Meta Description', 'tube-seo'),
            [$this, 'render_description_field'],
            self::PAGE_SLUG,
            self::SECTION
        );
    }

    /**
     * Render the screen (the page chrome + `do_settings_sections()`), via its view.
     */
    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'tube-seo'));
        }

        $tube_seo_page_slug    = self::PAGE_SLUG;
        $tube_seo_option_group = self::OPTION_GROUP;

        require __DIR__ . '/views/homepage-seo-settings.php';
    }

    /**
     * Render the Homepage SEO Title `<input>` + its live character counter.
     */
    public function render_title_field(): void
    {
        $value = get_option(self::OPTION_TITLE, '');
        $value = is_string($value) ? $value : '';

        require __DIR__ . '/views/homepage-title-field.php';
    }

    /**
     * Render the Homepage Meta Description `<textarea>` + its live character counter.
     */
    public function render_description_field(): void
    {
        $value = get_option(self::OPTION_DESCRIPTION, '');
        $value = is_string($value) ? $value : '';

        require __DIR__ . '/views/homepage-description-field.php';
    }

    /**
     * The recommended SEO title length range, for the view's counter guidance text.
     *
     * @return array{0: int, 1: int}
     */
    public static function title_recommended_range(): array
    {
        return [self::TITLE_RECOMMENDED_MIN, self::TITLE_RECOMMENDED_MAX];
    }

    /**
     * The recommended meta description length range, for the view's counter guidance text.
     *
     * @return array{0: int, 1: int}
     */
    public static function description_recommended_range(): array
    {
        return [self::DESCRIPTION_RECOMMENDED_MIN, self::DESCRIPTION_RECOMMENDED_MAX];
    }
}
