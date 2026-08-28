<?php
/**
 * Reads/writes the tube-ads settings option.
 *
 * @package Tube_Ads
 */

declare(strict_types=1);

namespace Tube_Ads\Admin;

use Tube_Ads\Placement\AdSettings;

/**
 * Reads/writes the tube-ads settings option — the one place
 * `get_option()`/`update_option()` is called for `self::OPTION`, so
 * every other class works with the real, typed `AdSettings` tree
 * instead. A plain WP option (not a dedicated table): this plugin owns
 * no data of its own beyond configuration, the same "options, not
 * tables, for pure configuration" posture `Tube_Seo\Sitemap\SitemapGenerator`
 * already documents for its own single option.
 */
final class SettingsRepository
{
    /**
     * The option name every tube-ads setting is stored under.
     */
    public const OPTION = 'tube_ads_settings';

    /**
     * The current settings, or `AdSettings::disabled()` if the option
     * has never been saved or is malformed — never fatals.
     */
    public function get(): AdSettings
    {
        $stored = get_option(self::OPTION, []);

        return AdSettings::from_array(is_array($stored) ? $stored : []);
    }

    /**
     * Persist a settings tree.
     *
     * @param AdSettings $settings The settings to store.
     */
    public function save(AdSettings $settings): void
    {
        update_option(self::OPTION, $settings->to_array(), false);
    }
}
