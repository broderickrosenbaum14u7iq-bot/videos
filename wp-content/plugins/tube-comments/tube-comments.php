<?php
/**
 * Plugin Name:       Tube Comments
 * Plugin URI:        https://phimtoico.org
 * Description:       Video comments, replies, likes, reports, moderation, timestamp links.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Requires Plugins:  tube-core, tube-members
 * Author:            Phim Toi Co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tube-comments
 *
 * Dedicated tables (`wp_tube_comments` and friends), not `wp_comments` —
 * see `Tube_Comments\Plugin`'s own docblock for the full storage-decision
 * writeup. Only authenticated members may write (create/reply/like/
 * report); guests may read. See `single-video.php`'s
 * `tube_comments_render_section()` call for the render hook this plugin
 * exposes to the theme.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const TUBE_COMMENTS_VERSION = '1.0.0';
const TUBE_COMMENTS_FILE    = __FILE__;
const TUBE_COMMENTS_DIR     = __DIR__;

if (file_exists(TUBE_COMMENTS_DIR . '/vendor/autoload.php')) {
    require_once TUBE_COMMENTS_DIR . '/vendor/autoload.php';
}

require_once TUBE_COMMENTS_DIR . '/includes/template-tags.php';

add_action(
    'plugins_loaded',
    static function (): void {
        \Tube_Comments\Plugin::instance()->boot();
    }
);
