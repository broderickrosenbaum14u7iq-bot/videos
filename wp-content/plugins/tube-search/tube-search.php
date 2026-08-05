<?php
/**
 * Plugin Name:       Tube Search
 * Plugin URI:        https://phimtoico.org
 * Description:       Discovery layer: search index, grid/listing/related/trending queries. See ARCHITECTURE.md.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Requires Plugins:  tube-core, tube-cache
 * Author:            Phim Toi Co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tube-search
 *
 * Phase 7: wp_tube_search_index table + migration, event-driven
 * incremental sync, index:rebuild WP-CLI command, and the discovery
 * query layer (related videos, trending, most viewed, recently added,
 * full-text search) — ARCHITECTURE.md §2.6/§12. Requires tube-cache (in
 * addition to tube-core) as of this phase: discovery results are cached
 * through tube-cache's own Redis-backed API.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const TUBE_SEARCH_VERSION = '0.1.0';
const TUBE_SEARCH_FILE    = __FILE__;
const TUBE_SEARCH_DIR     = __DIR__;

if (file_exists(TUBE_SEARCH_DIR . '/vendor/autoload.php')) {
    require_once TUBE_SEARCH_DIR . '/vendor/autoload.php';
}

require_once TUBE_SEARCH_DIR . '/includes/template-tags.php';

add_action(
    'plugins_loaded',
    static function (): void {
        \Tube_Search\Plugin::instance()->boot();
    }
);

register_activation_hook(TUBE_SEARCH_FILE, [\Tube_Search\Plugin::class, 'activate']);
register_deactivation_hook(TUBE_SEARCH_FILE, [\Tube_Search\Plugin::class, 'deactivate']);
