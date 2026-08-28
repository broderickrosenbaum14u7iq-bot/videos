<?php
/**
 * "Tube Ads" admin settings screen.
 *
 * @package Tube_Ads
 */

declare(strict_types=1);

namespace Tube_Ads\Admin;

use Tube_Ads\Placement\AdSettings;
use Tube_Ads\Placement\Placement;

/**
 * The "Tube Ads" admin settings screen — one page, tabbed via a `tab`
 * query arg (the same lightweight pattern most WordPress core settings
 * screens use), backed by a single `register_setting()` option
 * (`Tube_Ads\Admin\SettingsRepository::OPTION`) so one save/nonce/
 * capability check covers every tab. `SettingsSanitizer` is the
 * `sanitize_callback` — this class only renders the form and reads the
 * current value back for display.
 */
final class SettingsScreen
{
    /**
     * This screen's (and its parent top-level menu's) slug.
     */
    public const PAGE_SLUG = 'tube-ads';

    /**
     * The capability required to view or change these settings.
     */
    private const CAPABILITY = 'manage_options';

    /**
     * Every tab this screen has, in display order.
     *
     * @var string[]
     */
    private const TABS = ['general', 'preroll', 'player', 'homepage', 'other', 'advanced'];

    /**
     * Construct around the collaborators this screen reads from.
     *
     * @param SettingsRepository $repository Reads the current settings.
     */
    public function __construct(private readonly SettingsRepository $repository)
    {
    }

    /**
     * Register the "Tube Ads" top-level menu. Called on `admin_menu`.
     */
    public function register_menu(): void
    {
        add_menu_page(
            __('Tube Ads', 'tube-ads'),
            __('Tube Ads', 'tube-ads'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render'],
            'dashicons-megaphone',
            81
        );
    }

    /**
     * Register the settings option and its sanitize callback. Called on `admin_init`.
     */
    public function register_settings(): void
    {
        register_setting(
            self::PAGE_SLUG,
            SettingsRepository::OPTION,
            [
                'type'              => 'array',
                'sanitize_callback' => [new SettingsSanitizer(), 'sanitize'],
                'default'           => [],
            ]
        );
    }

    /**
     * Render the screen.
     */
    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'tube-ads'));
        }

        $tube_ads_settings   = $this->repository->get();
        $tube_ads_option     = SettingsRepository::OPTION;
        $tube_ads_page_slug  = self::PAGE_SLUG;
        $tube_ads_active_tab = self::active_tab();
        $tube_ads_tab_labels = self::tab_labels();

        require __DIR__ . '/views/screen.php';
    }

    /**
     * The currently-selected tab, from the `tab` query arg — falls back
     * to `general` for a missing/unrecognized value.
     */
    private static function active_tab(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection, no state change; the form itself is nonce-protected by settings_fields().
        $requested = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key($_GET['tab']) : '';

        return in_array($requested, self::TABS, true) ? $requested : 'general';
    }

    /**
     * Every tab's display label, keyed by its slug.
     *
     * @return array<string, string>
     */
    private static function tab_labels(): array
    {
        return [
            'general'  => __('General', 'tube-ads'),
            'preroll'  => __('Pre-roll', 'tube-ads'),
            'player'   => __('Player Ads', 'tube-ads'),
            'homepage' => __('Homepage Ads', 'tube-ads'),
            'other'    => __('Other Placements', 'tube-ads'),
            'advanced' => __('Advanced', 'tube-ads'),
        ];
    }
}
