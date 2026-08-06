<?php
/**
 * Plugin Name:       Tube Admin
 * Plugin URI:        https://phimtoico.org
 * Description:       Operational wp-admin UI: import/stats dashboards, video/actor/studio management.
 * Version:           1.0.1
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Requires Plugins:  tube-core
 * Author:            Phim Toi Co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tube-admin
 *
 * Phase 10: the `wp-admin`-only operational UI over tube-core's existing
 * data (import queue, statistics, video metadata, actor/studio
 * relationships) — ARCHITECTURE.md §12 Phase 10's deliverable. Owns no
 * database tables of its own (ARCHITECTURE.md §4); every write goes
 * through tube-core's repositories.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const TUBE_ADMIN_VERSION = '1.0.1';
const TUBE_ADMIN_FILE    = __FILE__;
const TUBE_ADMIN_DIR     = __DIR__;

if (file_exists(TUBE_ADMIN_DIR . '/vendor/autoload.php')) {
    require_once TUBE_ADMIN_DIR . '/vendor/autoload.php';
}

// Unlike tube-core/tube-player/tube-search/tube-seo, tube-admin has no
// migrations and no rewrite rules of its own (ARCHITECTURE.md §4: "no
// tables") -- nothing for an activate()/deactivate() pair to do, the
// same reasoning tube-cache's bootstrap already documents for skipping
// them entirely rather than shipping empty stub methods.
add_action(
    'plugins_loaded',
    static function (): void {
        \Tube_Admin\Plugin::instance()->boot();
    }
);
