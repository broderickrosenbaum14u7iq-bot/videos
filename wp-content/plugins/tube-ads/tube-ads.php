<?php
/**
 * Plugin Name:       Tube Ads
 * Plugin URI:        https://phimtoico.org
 * Description:       Modular, provider-neutral advertising system: VAST pre-roll, display/banner placements.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Author:            Phim Toi Co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tube-ads
 *
 * Provider-neutral by design: no ad network is hardcoded anywhere in
 * this plugin. Every VAST tag, display creative, and device-targeting
 * rule is configured in WordPress Admin ("Tube Ads") and stored as
 * ordinary site configuration, so this same codebase is reusable
 * as-is across independently-cloned sites (2026-08-26 §26).
 *
 * Deliberately has NO `Requires Plugins` header: it integrates with
 * tube-player/tube-theme entirely through the DOM's already-public
 * selectors/CSS classes (see `Tube_Ads\Plugin`'s own docblock) rather
 * than a direct PHP dependency, so it can be activated, deactivated, or
 * omitted from a clone with zero effect on any other plugin's own
 * behavior — the actual "no regression when ads are disabled"
 * requirement, structural rather than just configured.
 *
 * @package Tube_Ads
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const TUBE_ADS_VERSION = '1.0.1';
const TUBE_ADS_FILE    = __FILE__;
const TUBE_ADS_DIR     = __DIR__;

if (file_exists(TUBE_ADS_DIR . '/vendor/autoload.php')) {
    require_once TUBE_ADS_DIR . '/vendor/autoload.php';
}

require_once TUBE_ADS_DIR . '/includes/template-tags.php';

add_action(
    'plugins_loaded',
    static function (): void {
        \Tube_Ads\Plugin::instance()->boot();
    }
);
