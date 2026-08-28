<?php
/**
 * Tube Ads' bootstrap.
 *
 * @package Tube_Ads
 */

declare(strict_types=1);

namespace Tube_Ads;

use Tube_Ads\Admin\SettingsRepository;
use Tube_Ads\Admin\SettingsScreen;
use Tube_Ads\Placement\Placement;
use Tube_Ads\Render\PlacementRenderer;

/**
 * Tube Ads' bootstrap — the composition-root shape every other tube-*
 * plugin already uses.
 *
 * `self::maybe_output_slots()` (on `wp_footer`) is this plugin's
 * non-invasive integration with the CURRENT site's already-approved
 * templates: `single-video.php`, `tube-theme.css`, `video-card.php`,
 * and every player PHP/JS file are a protected baseline this phase must
 * not edit (2026-08-26 audit), so rather than calling `tube_ads_render()`
 * from inside them, this hook echoes each relevant placement's markup
 * into an inert `<template data-tube-ads-slot="...">` tag at the end of
 * the page — invisible, non-rendering, and (critically) not yet
 * containing a live, auto-executing `<script>` — and `assets/js/
 * tube-ads-display.js` moves each one's *content* to its real DOM
 * position (next to `.video-player-wrap`, inside `.watch-layout__sidebar`,
 * etc. — all stable selectors already in the live-rendered page, found by
 * inspecting the existing templates, not by editing them) once the page
 * has loaded, executing any `<script>` tag it contains at that point via
 * the standard clone-and-replace technique (script tags inside a cloned
 * `<template>`/`innerHTML` never auto-execute otherwise). A future
 * cloned site's own theme is free to call `tube_ads_render()` directly
 * instead — see `includes/template-tags.php`'s own docblock.
 *
 * Pre-roll's actual gate — intercepting the existing `.tube-player__play`
 * click before `tube-player.js`'s own `activate()` runs — needed even
 * less coupling than that: `assets/js/tube-ads-preroll.js` attaches its
 * own capture-phase `document` click listener for the same, already-
 * public `.tube-player__play`/`[data-tube-player]` selectors
 * `tube-player.js` itself uses, found by reading that file (never
 * edited) — see that script's own header comment for the full flow and
 * why it can never call `activate()` twice.
 */
final class Plugin
{
    /**
     * The shared instance, lazily created by self::instance().
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Lazily created by self::settings_repository().
     *
     * @var SettingsRepository|null
     */
    private ?SettingsRepository $settings_repository = null;

    /**
     * Lazily created by self::placement_renderer().
     *
     * @var PlacementRenderer|null
     */
    private ?PlacementRenderer $placement_renderer = null;

    /**
     * Every placement relevant to a video single page.
     *
     * @var list<Placement>
     */
    private const WATCH_PAGE_PLACEMENTS = [
        Placement::PlayerAbove,
        Placement::PlayerBelow,
        Placement::WatchSidebarTop,
        Placement::WatchSidebarMiddle,
        Placement::RelatedGrid,
    ];

    /**
     * Every placement relevant to the homepage.
     *
     * @var list<Placement>
     */
    private const HOMEPAGE_PLACEMENTS = [
        Placement::HomepageTop,
        Placement::HomepageBetweenSections,
    ];

    /**
     * Private: use self::instance() instead.
     */
    private function __construct()
    {
    }

    /**
     * The shared Plugin instance.
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Wire up hooks. Called on `plugins_loaded`.
     */
    public function boot(): void
    {
        $settings_screen = new SettingsScreen($this->settings_repository());

        add_action('admin_menu', [$settings_screen, 'register_menu']);
        add_action('admin_init', [$settings_screen, 'register_settings']);

        // Both hooks below read the settings once each and return
        // immediately when ads are globally disabled (2026-08-26 §20
        // performance requirement) — no query beyond the one already-
        // cached `get_option()` call, no script/style enqueued, no
        // markup echoed, the page is byte-for-byte what it would be
        // without this plugin active at all.
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_assets']);
        add_action('wp_footer', [$this, 'maybe_output_slots']);
    }

    /**
     * The settings repository.
     */
    public function settings_repository(): SettingsRepository
    {
        if (null === $this->settings_repository) {
            $this->settings_repository = new SettingsRepository();
        }

        return $this->settings_repository;
    }

    /**
     * The placement renderer.
     *
     * Public: `includes/template-tags.php`'s `tube_ads_render()` is a thin wrapper around this.
     */
    public function placement_renderer(): PlacementRenderer
    {
        if (null === $this->placement_renderer) {
            $this->placement_renderer = new PlacementRenderer($this->settings_repository());
        }

        return $this->placement_renderer;
    }

    /**
     * Enqueue this plugin's CSS/JS — only when ads are globally enabled,
     * and only the pre-roll scripts when pre-roll is itself active for
     * the current page type. Called on `wp_enqueue_scripts`.
     */
    public function maybe_enqueue_assets(): void
    {
        $settings = $this->settings_repository()->get();

        if (! $settings->enabled) {
            return;
        }

        wp_enqueue_style(
            'tube-ads',
            plugins_url('assets/css/tube-ads.css', TUBE_ADS_FILE),
            [],
            self::asset_version('assets/css/tube-ads.css')
        );

        wp_enqueue_script(
            'tube-ads-display',
            plugins_url('assets/js/tube-ads-display.js', TUBE_ADS_FILE),
            [],
            self::asset_version('assets/js/tube-ads-display.js'),
            true
        );

        // Pre-roll's own request only ever happens client-side, on a
        // real play click (2026-08-26 §20 — "Play click -> request
        // VAST," never on page load) — but the scripts/config
        // themselves are only worth shipping at all on a video page
        // with pre-roll actually eligible for at least one device.
        if (is_singular('video') && $settings->preroll->is_active()) {
            wp_enqueue_script(
                'tube-ads-vast',
                plugins_url('assets/js/tube-ads-vast.js', TUBE_ADS_FILE),
                [],
                self::asset_version('assets/js/tube-ads-vast.js'),
                true
            );

            wp_enqueue_script(
                'tube-ads-preroll',
                plugins_url('assets/js/tube-ads-preroll.js', TUBE_ADS_FILE),
                ['tube-ads-vast'],
                self::asset_version('assets/js/tube-ads-preroll.js'),
                true
            );

            wp_localize_script(
                'tube-ads-preroll',
                'TubeAdsConfig',
                [
                    'debug'   => $settings->debug,
                    'preroll' => [
                        'vastUrl'            => $settings->effective_vast_url(),
                        'advertiserUrl'      => $settings->preroll->advertiser_url,
                        'skipEnabled'        => $settings->preroll->skip_enabled,
                        'skipAfterSeconds'   => $settings->preroll->skip_after_seconds,
                        'maxDurationSeconds' => $settings->preroll->max_duration_seconds,
                        'timeoutSeconds'     => $settings->preroll->timeout_seconds,
                        'desktopEnabled'     => $settings->preroll->desktop_enabled,
                        'mobileEnabled'      => $settings->preroll->mobile_enabled,
                        'frequency'          => $settings->preroll->frequency->value,
                        'frequencyMinutes'   => $settings->preroll->frequency_minutes,
                    ],
                ]
            );
        }
    }

    /**
     * Echo this page's relevant placements as inert `<template>` tags,
     * plus the global custom script if active — see this class's own
     * docblock for the full reasoning. Called on `wp_footer`.
     */
    public function maybe_output_slots(): void
    {
        $settings = $this->settings_repository()->get();

        if (! $settings->enabled) {
            return;
        }

        $renderer = $this->placement_renderer();

        if (is_singular('video')) {
            foreach (self::WATCH_PAGE_PLACEMENTS as $placement) {
                self::echo_slot_template($placement, $renderer);
            }
        }

        if (is_front_page()) {
            foreach (self::HOMEPAGE_PLACEMENTS as $placement) {
                self::echo_slot_template($placement, $renderer);
            }
        }

        self::echo_slot_template(Placement::FooterBanner, $renderer);

        if ($settings->global_script->is_active()) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already sanitized on save (SettingsSanitizer), the one deliberate raw-code output point for this placement.
            echo $settings->global_script->code;
        }
    }

    /**
     * The cache-busting `$ver` for one of this plugin's own JS/CSS
     * files — the file's own mtime, not the fixed `TUBE_ADS_VERSION`
     * plugin-version constant. This project's static assets are served
     * with a long (30-day) `Cache-Control` by nginx, keyed only on the
     * request URL (which includes `$ver`); with a version string that
     * never changes between edits, a browser that fetched a script
     * before a fix would keep serving that exact stale copy for up to a
     * month even though the option/PHP/HTML the fix touches is already
     * correct everywhere else -- found live during QA (2026-08-27):
     * `skip_after_seconds` was 8 all the way from wp_options through the
     * localized page config, yet the pre-roll still behaved like the
     * old value, because the ALREADY-FIXED tube-ads-preroll.js was still
     * sitting in a real browser's cache under the same unchanged
     * `?ver=1.0.0` URL. `filemtime()` ties the query string to the
     * file's actual last-modified time, so any future edit is a new
     * URL and can never collide with a previously cached copy.
     *
     * @param string $relative_path Path under this plugin's own directory, e.g. `assets/js/tube-ads-preroll.js`.
     */
    private static function asset_version(string $relative_path): string
    {
        $path = TUBE_ADS_DIR . '/' . $relative_path;

        if (! file_exists($path)) {
            return TUBE_ADS_VERSION;
        }

        $mtime = filemtime($path);

        return false !== $mtime ? (string) $mtime : TUBE_ADS_VERSION;
    }

    /**
     * Echo one placement's markup wrapped in an inert `<template>` tag, or nothing if it has no markup to show.
     *
     * @param Placement         $placement The placement to render.
     * @param PlacementRenderer $renderer  Renders it.
     */
    private static function echo_slot_template(Placement $placement, PlacementRenderer $renderer): void
    {
        $markup = $renderer->render($placement);

        if ('' === $markup) {
            return;
        }

        // Only Placement::RelatedGrid's position is ever needed client-side
        // (assets/js/tube-ads-display.js reads it to know which card index
        // to insert after) — every other placement has exactly one DOM anchor.
        $position_attr = Placement::RelatedGrid === $placement
            ? sprintf(' data-position="%d"', $renderer->grid_position($placement))
            : '';

        printf(
            '<template data-tube-ads-slot="%s"%s>%s</template>',
            esc_attr($placement->value),
            $position_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built entirely from esc_attr()/%d above, never raw input.
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() already escapes everything except the pre-sanitized custom-HTML/JS field (see its docblock).
            $markup
        );
    }
}
