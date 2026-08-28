<?php
/**
 * Plugin Name:       Tube Members
 * Plugin URI:        https://phimtoico.org
 * Description:       Frontend member registration/login, profile, avatar, Google OAuth architecture.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Requires Plugins:  tube-core
 * Author:            Phim Toi Co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tube-members
 *
 * Frontend member system built on WordPress's own users
 * (`wp_users`/`wp_usermeta`, core password hashing, core auth cookies,
 * core nonces) — no second password database. Normal members are the
 * `subscriber` role with no backend capabilities; see
 * `Tube_Members\Capability\MemberRoleGuard` for the wp-admin restriction.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const TUBE_MEMBERS_VERSION = '1.0.0';
const TUBE_MEMBERS_FILE    = __FILE__;
const TUBE_MEMBERS_DIR     = __DIR__;

if (file_exists(TUBE_MEMBERS_DIR . '/vendor/autoload.php')) {
    require_once TUBE_MEMBERS_DIR . '/vendor/autoload.php';
}

require_once TUBE_MEMBERS_DIR . '/includes/template-tags.php';

add_action(
    'plugins_loaded',
    static function (): void {
        \Tube_Members\Plugin::instance()->boot();
    }
);

register_activation_hook(TUBE_MEMBERS_FILE, [\Tube_Members\Plugin::class, 'activate']);
register_deactivation_hook(TUBE_MEMBERS_FILE, [\Tube_Members\Plugin::class, 'deactivate']);
