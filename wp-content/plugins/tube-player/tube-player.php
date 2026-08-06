<?php
/**
 * Plugin Name:       Tube Player
 * Plugin URI:        https://phimtoico.org
 * Description:       Playback rendering: CF Stream URLs from stored UIDs, click-to-load embed. See ARCHITECTURE.md.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Requires Plugins:  tube-core
 * Author:            Phim Toi Co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tube-player
 *
 * Phase 6: Cloudflare Stream URL construction (unsigned by default,
 * signed if configured), click-to-load embed, poster/thumbnail
 * rendering with the Cloudflare Images override path (ARCHITECTURE.md
 * §8/§12). No MySQL tables of its own (ARCHITECTURE.md §4).
 *
 * @package Tube_Player
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const TUBE_PLAYER_VERSION = '1.0.0';
const TUBE_PLAYER_FILE    = __FILE__;
const TUBE_PLAYER_DIR     = __DIR__;

if (file_exists(TUBE_PLAYER_DIR . '/vendor/autoload.php')) {
    require_once TUBE_PLAYER_DIR . '/vendor/autoload.php';
}

require_once TUBE_PLAYER_DIR . '/includes/template-tags.php';
