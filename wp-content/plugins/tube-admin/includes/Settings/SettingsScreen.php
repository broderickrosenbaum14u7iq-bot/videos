<?php
/**
 * The read-only Cloudflare/Redis configuration status page.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Settings;

use Tube_Admin\Plugin;

/**
 * A read-only display of whether each Cloudflare/Redis configuration
 * constant is set — not an editable settings UI. Per this project's own
 * explicit decision (recorded here, not silently assumed): every one of
 * these values is a plain PHP `define()` set at container boot via
 * `docker-compose.yml`'s `WORDPRESS_CONFIG_EXTRA` (see
 * `ARCHITECTURE.md`'s environment wiring) — a `wp-admin` settings page
 * cannot actually change a `define()`'d constant at runtime without a
 * new constant-default/database-override settings-storage layer, which
 * is itself a small architecture addition `ARCHITECTURE.md` doesn't
 * currently specify and was not requested for this phase. Building an
 * editable form over values that silently wouldn't take effect would be
 * actively misleading, not a shortcut; see `PHASE-10.md`'s design
 * decisions for the full reasoning. Values are edited via
 * `docker-compose.yml`/`.env` and take effect on the next
 * `docker compose up -d`.
 *
 * Never displays the actual secret values (webhook secret, API tokens,
 * signing keys) — only whether each is set, which is all an operator
 * needs to diagnose a misconfiguration without this screen itself
 * becoming a secret-disclosure surface.
 */
final class SettingsScreen
{
    /**
     * This screen's menu slug.
     */
    public const SLUG = 'tube-admin-settings';

    /**
     * Constants this screen reports on, and their display labels.
     *
     * @var array<string, string>
     */
    private const CONSTANTS = [
        'TUBE_CORE_REDIS_HOST'                         => 'Redis Host',
        'TUBE_CORE_CLOUDFLARE_STREAM_WEBHOOK_SECRET'   => 'Cloudflare Stream Webhook Secret',
        'TUBE_PLAYER_CLOUDFLARE_STREAM_CUSTOMER_CODE'  => 'Cloudflare Stream Customer Code',
        'TUBE_PLAYER_CLOUDFLARE_STREAM_SIGNING_KEY_ID' => 'Cloudflare Stream Signing Key (optional)',
        'TUBE_PLAYER_CLOUDFLARE_IMAGES_ACCOUNT_HASH'   => 'Cloudflare Images Account Hash (rendering)',
        'TUBE_ADMIN_CLOUDFLARE_IMAGES_ACCOUNT_ID'      => 'Cloudflare Images Account ID (upload)',
        'TUBE_ADMIN_CLOUDFLARE_IMAGES_API_TOKEN'       => 'Cloudflare Images API Token (upload)',
    ];

    /**
     * Render the screen.
     */
    public function render(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'tube-admin'));
        }

        $rows = [];

        foreach (self::CONSTANTS as $constant_name => $label) {
            $rows[] = [
                'label' => $label,
                'set'   => self::is_constant_set($constant_name),
            ];
        }

        require __DIR__ . '/views/settings.php';
    }

    /**
     * Whether a constant is defined and holds a non-empty string —
     * never reads or exposes the value itself.
     *
     * @param string $name The constant's name.
     */
    private static function is_constant_set(string $name): bool
    {
        if (! defined($name)) {
            return false;
        }

        $value = constant($name);

        return is_string($value) && '' !== $value;
    }
}
