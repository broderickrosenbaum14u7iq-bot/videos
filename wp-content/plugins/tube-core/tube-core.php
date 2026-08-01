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
 * Phase 1: video CPT, video_category/video_tag taxonomies, the schema
 * migration framework, and the actor/studio/video-metadata tables. The
 * event dispatcher and the rest of the tables (views, statistics, import
 * queue, watch history) are built in later phases, per the approved
 * architecture (ARCHITECTURE.md).
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

if (file_exists(TUBE_CORE_DIR . '/vendor/autoload.php')) {
    require_once TUBE_CORE_DIR . '/vendor/autoload.php';
}

add_action(
    'plugins_loaded',
    static function (): void {
        \Tube_Core\Plugin::instance()->boot();
    }
);

register_activation_hook(TUBE_CORE_FILE, [\Tube_Core\Plugin::class, 'activate']);
register_deactivation_hook(TUBE_CORE_FILE, [\Tube_Core\Plugin::class, 'deactivate']);
