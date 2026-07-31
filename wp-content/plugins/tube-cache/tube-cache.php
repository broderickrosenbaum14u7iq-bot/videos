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
 * Phase 0 scaffold only, no business logic yet. Built in its assigned
 * implementation phase per the approved architecture (ARCHITECTURE.md).
 *
 * Note: unlike the other four dependent plugins, tube-cache does not
 * declare "Requires Plugins: tube-core" - per ARCHITECTURE.md section 4 it
 * is an independent utility consumed by tube-core and tube-search, not
 * dependent on tube-core itself.
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
