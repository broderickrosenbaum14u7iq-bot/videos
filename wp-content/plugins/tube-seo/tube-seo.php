<?php
/**
 * Plugin Name:       Tube SEO
 * Plugin URI:        https://phimtoico.org
 * Description:       Meta tags, VideoObject JSON-LD schema, canonical policy. See ARCHITECTURE.md.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Requires Plugins:  tube-core, tube-player, tube-search
 * Author:            Phim Toi Co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tube-seo
 *
 * Phase 8: per-request `<title>`/meta description/canonical/robots/
 * OpenGraph/Twitter Card tags, plus JSON-LD (`VideoObject`,
 * `BreadcrumbList`, `CollectionPage`) — ARCHITECTURE.md §12 Phase 8's
 * pulled-forward SEO scope (the deliverable ARCHITECTURE.md §12
 * otherwise assigns to Phase 9). Video sitemap generation stays
 * deferred — not part of this phase's explicit SEO deliverable list.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const TUBE_SEO_VERSION = '0.1.0';
const TUBE_SEO_FILE    = __FILE__;
const TUBE_SEO_DIR     = __DIR__;

if (file_exists(TUBE_SEO_DIR . '/vendor/autoload.php')) {
    require_once TUBE_SEO_DIR . '/vendor/autoload.php';
}

require_once TUBE_SEO_DIR . '/includes/template-tags.php';

add_action(
    'plugins_loaded',
    static function (): void {
        \Tube_Seo\Plugin::instance()->boot();
    }
);
