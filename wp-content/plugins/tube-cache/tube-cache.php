<?php
/**
 * Plugin Name:       Tube Cache
 * Plugin URI:        https://phimtoico.org
 * Description:       Caching infrastructure. Independent utility, no MySQL tables. See ARCHITECTURE.md.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Author:            Phim Toi Co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tube-cache
 *
 * Phase 3: Redis connection layer, caching API, rate-limit helper, and
 * the cache-purge event subscriber (ARCHITECTURE.md §12). Fragment cache
 * and Cloudflare edge-purge (the rest of §16's four cache layers) are not
 * built yet — they depend on a real theme (Phase 8) and edge integration
 * that don't exist yet; see PHASE-3.md.
 *
 * Note: unlike the other four dependent plugins, tube-cache does not
 * declare "Requires Plugins: tube-core" - per ARCHITECTURE.md section 4 it
 * is an independent utility consumed by tube-core and tube-search, not
 * dependent on tube-core itself. CachePurgeSubscriber subscribes to
 * tube-core's hooks by name (see its own docblock) specifically so this
 * plugin degrades gracefully — not fatally — if tube-core is inactive.
 *
 * @package Tube_Cache
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const TUBE_CACHE_VERSION = '0.1.0';
const TUBE_CACHE_FILE    = __FILE__;
const TUBE_CACHE_DIR     = __DIR__;

if (file_exists(TUBE_CACHE_DIR . '/vendor/autoload.php')) {
    require_once TUBE_CACHE_DIR . '/vendor/autoload.php';
}

add_action(
    'plugins_loaded',
    static function (): void {
        \Tube_Cache\Plugin::instance()->boot();
    }
);
