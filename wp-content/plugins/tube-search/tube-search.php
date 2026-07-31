<?php
/**
 * Plugin Name:       Tube Search
 * Plugin URI:        https://phimtoico.org
 * Description:       Discovery layer: search index, grid/listing/related/trending queries. See ARCHITECTURE.md.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Requires Plugins:  tube-core
 * Author:            Phim Toi Co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tube-search
 *
 * Phase 0 scaffold only, no business logic yet. Built in its assigned
 * implementation phase per the approved architecture (ARCHITECTURE.md).
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
