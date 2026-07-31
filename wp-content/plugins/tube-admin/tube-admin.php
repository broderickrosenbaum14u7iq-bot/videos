<?php
/**
 * Plugin Name:       Tube Admin
 * Plugin URI:        https://phimtoico.org
 * Description:       Operational wp-admin UI: import/stats dashboards, actor/studio management. See ARCHITECTURE.md.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Requires Plugins:  tube-core
 * Author:            Phim Toi Co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tube-admin
 *
 * Phase 0 scaffold only, no business logic yet. Built in its assigned
 * implementation phase per the approved architecture (ARCHITECTURE.md).
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const TUBE_ADMIN_VERSION = '0.1.0';
const TUBE_ADMIN_FILE    = __FILE__;
const TUBE_ADMIN_DIR     = __DIR__;
