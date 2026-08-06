<?php
/**
 * The system status page: Redis connectivity, migration status, cron configuration.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Status;

use Tube_Admin\Plugin;
use Tube_Core\Plugin as Tube_Core_Plugin;

/**
 * The `wp-admin` operational health surface: real, currently-measurable
 * signals only — Redis TCP reachability, migration status per plugin
 * (via `tube-core`'s shared `MigrationRunner`, which every plugin with
 * migrations registers onto — currently `tube-core` and `tube-search`,
 * per their own `boot()` wiring), and whether `DISABLE_WP_CRON` is
 * actually set (ARCHITECTURE.md §7 requires it).
 *
 * Deliberately does not show "last cron job run" timestamps: no job in
 * this project logs its own last-run time anywhere queryable (confirmed
 * by reading every `wp tube-core`/`wp tube-search`/`wp tube-seo` CLI
 * command — none writes a completion marker), so surfacing that would
 * mean fabricating data, which `DEVELOPMENT_RULES.md` §2's "production
 * quality, no placeholder content" rule prohibits. A real per-job
 * last-run log is a genuine future need, not something to fake here; see
 * `PHASE-10.md`.
 *
 * WordPress-coupled throughout and integration/live-tested only, the
 * same split every other screen in this plugin uses.
 */
final class SystemStatusScreen
{
    /**
     * This screen's menu slug.
     */
    public const SLUG = 'tube-admin-status';

    /**
     * How long to wait for a Redis TCP connection before treating it as unreachable.
     */
    private const REDIS_CONNECT_TIMEOUT_SECONDS = 2.0;

    /**
     * Render the screen.
     */
    public function render(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'tube-admin'));
        }

        $redis_host = defined('TUBE_CORE_REDIS_HOST') && is_string(TUBE_CORE_REDIS_HOST)
            ? TUBE_CORE_REDIS_HOST
            : '127.0.0.1';
        $redis_port = defined('TUBE_CORE_REDIS_PORT') && is_int(TUBE_CORE_REDIS_PORT)
            ? TUBE_CORE_REDIS_PORT
            : 6379;

        $redis_status = $this->check_tcp_reachable($redis_host, $redis_port);

        $migration_status = Tube_Core_Plugin::instance()->migration_runner()->status();
        $wp_cron_disabled = defined('DISABLE_WP_CRON') && true === DISABLE_WP_CRON;

        require __DIR__ . '/views/status.php';
    }

    /**
     * Check whether a TCP connection can be opened to a host:port —
     * used for the Redis reachability indicator. A successful connect is
     * closed immediately; no protocol-level command is sent, so this
     * confirms network reachability only, not that Redis itself is
     * healthy (e.g. out of memory) — a narrower, but still real and
     * honest, signal.
     *
     * @param string $host The hostname or IP to connect to.
     * @param int    $port The TCP port to connect to.
     *
     * @return array{reachable: bool, message: string}
     */
    private function check_tcp_reachable(string $host, int $port): array
    {
        $errno  = 0;
        $errstr = '';

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen, WordPress.PHP.NoSilencedErrors.Discouraged -- a raw TCP reachability probe, not a filesystem operation WP_Filesystem has any equivalent for; @ suppresses fsockopen()'s own E_WARNING on failure, which the false-return check immediately below already handles explicitly.
        $connection = @fsockopen($host, $port, $errno, $errstr, self::REDIS_CONNECT_TIMEOUT_SECONDS);

        if (false === $connection) {
            return [
                'reachable' => false,
                'message'   => sprintf('%s:%d — %s', $host, $port, '' === $errstr ? 'connection failed' : $errstr),
            ];
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the socket opened by fsockopen() above, not a filesystem operation.
        fclose($connection);

        return [
            'reachable' => true,
            'message'   => sprintf('%s:%d', $host, $port),
        ];
    }
}
