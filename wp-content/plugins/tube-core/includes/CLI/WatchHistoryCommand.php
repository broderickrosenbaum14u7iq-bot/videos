<?php
/**
 * `wp tube-core watch-history:purge`.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\CLI;

use Tube_Core\WatchHistory\GuestHistoryRetention;
use WP_CLI;

/**
 * `wp tube-core watch-history:purge` — purges stale guest watch-history
 * rows (ARCHITECTURE.md §7, nightly).
 */
final class WatchHistoryCommand
{
    /**
     * Construct the command around the retention job it drives.
     *
     * @param GuestHistoryRetention $retention Drives the purge.
     */
    public function __construct(private readonly GuestHistoryRetention $retention)
    {
    }

    /**
     * Purge guest watch-history rows older than the retention window.
     *
     * ## EXAMPLES
     *
     *     wp tube-core watch-history:purge
     *
     * @when after_wp_load
     */
    public function purge(): void
    {
        $deleted = $this->retention->purge();

        WP_CLI::success(sprintf('Purged %d stale guest watch-history row(s).', $deleted));
    }
}
