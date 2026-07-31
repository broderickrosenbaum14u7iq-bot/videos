<?php
/**
 * Plugin Name:       Tube Player
 * Plugin URI:        https://phimtoico.org
 * Description:       Playback rendering: CF Stream URLs from stored UIDs, click-to-load embed. See ARCHITECTURE.md.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Requires Plugins:  tube-core
 * Author:            Phim Toi Co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tube-player
 *
 * Phase 0 scaffold only, no business logic yet. Built in its assigned
 * implementation phase per the approved architecture (ARCHITECTURE.md).
 *
 * @package Tube_Player
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const TUBE_PLAYER_VERSION = '0.1.0';
const TUBE_PLAYER_FILE    = __FILE__;
const TUBE_PLAYER_DIR     = __DIR__;
