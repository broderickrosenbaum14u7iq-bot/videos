<?php
/**
 * Purges stale guest watch-history rows. Backs `wp tube-core watch-history:purge`.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\WatchHistory;

use Tube_Core\WatchHistory\Repositories\WatchHistoryRepositoryInterface;

/**
 * Purges stale guest watch-history rows — the pure logic behind
 * `wp tube-core watch-history:purge` (ARCHITECTURE.md §7, nightly).
 *
 * Logged-in users' history is never purged this way (§8/§12 — it's tied
 * to their account, not a stale-anonymous-tracking concern); only rows
 * with a `visitor_token` (no `user_id`) are eligible, enforced by
 * `WatchHistoryRepositoryInterface::purge_stale_guests()` itself.
 */
final class GuestHistoryRetention
{
    /**
     * How long a guest's watch history survives without being updated —
     * long enough that a returning visitor within a normal browsing
     * cadence keeps their progress, short enough that permanently-gone
     * visitors don't accumulate indefinitely.
     */
    private const RETENTION_DAYS = 180;

    /**
     * A literal 86400, not WordPress's DAY_IN_SECONDS — this class is
     * deliberately WordPress-independent (unit-tested without WordPress
     * loaded, the same reasoning `Tube_Core\Views\Retention` documents
     * for the same choice).
     */
    private const SECONDS_PER_DAY = 86400;

    /**
     * Construct around the repository stale rows are purged from.
     *
     * @param WatchHistoryRepositoryInterface $repository Purged from this.
     */
    public function __construct(private readonly WatchHistoryRepositoryInterface $repository)
    {
    }

    /**
     * Purge every guest history row untouched since before the retention window.
     *
     * @return int The number of rows deleted.
     */
    public function purge(): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * self::SECONDS_PER_DAY);

        return $this->repository->purge_stale_guests($cutoff);
    }
}
