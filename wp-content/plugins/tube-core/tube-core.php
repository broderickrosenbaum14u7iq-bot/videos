<?php
/**
 * Plugin Name:       Tube Core
 * Plugin URI:        https://phimtoico.org
 * Description:       Foundation plugin: video CPT, taxonomies, tables, migrations, events. See ARCHITECTURE.md.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Author:            Phim Toi Co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tube-core
 *
 * Phase 0 scaffold only, no business logic yet. CPT/taxonomy registration,
 * the data tables, the migration runner, and the event dispatcher are built
 * in Phase 1 onward, per the approved architecture (ARCHITECTURE.md).
 *
 * @package Tube_Core
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const TUBE_CORE_VERSION = '0.1.0';
const TUBE_CORE_FILE    = __FILE__;
const TUBE_CORE_DIR     = __DIR__;
