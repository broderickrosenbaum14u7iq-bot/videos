<?php
/**
 * Purges old view-bucket rows. Backs `wp tube-core views:partition-maintenance`.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Views;

use Tube_Core\Views\Repositories\VideoViewsRepositoryInterface;

/**
 * Purges old wp_tube_video_views rows — the pure logic behind
 * `wp tube-core views:partition-maintenance` (ARCHITECTURE.md §7,
 * nightly).
 *
 * Named to match ARCHITECTURE.md §7's documented WP-CLI command exactly,
 * even though the implementation is a plain indexed `DELETE`, not MySQL
 * native `PARTITION BY`/`DROP PARTITION` — see
 * `Migration005CreateVideoViewsTable`'s docblock for the full reasoning
 * (this project's real target scale doesn't need partitioning's
 * complexity to make retention fast).
 *
 * Safe regardless of retention length: `views_total` in
 * wp_tube_video_statistics is a running counter (`ViewsFlusher`), never
 * derived from wp_tube_video_views, so purging old buckets here never
 * corrupts a video's all-time total — only the raw hourly detail ages
 * out, which nothing downstream depends on past the 30-day window
 * `stats:rollup` actually reads.
 */
final class Retention
{
    /**
     * How many days of raw hourly buckets to keep — comfortably beyond
     * the 30-day window `StatsRollup` reads, so retention length has no
     * way to affect a correct rollup even under an unusually delayed
     * cron run.
     */
    private const RETENTION_DAYS = 90;

    /**
     * A literal 86400, not WordPress's DAY_IN_SECONDS — this class is
     * deliberately WordPress-independent (see this file's own docblock
     * on why it's unit-tested without WordPress loaded), and
     * DAY_IN_SECONDS is a WordPress-defined constant, not a PHP one.
     */
    private const SECONDS_PER_DAY = 86400;

    /**
     * Construct around the repository rows are purged from.
     *
     * @param VideoViewsRepositoryInterface $views_repository Purged from this.
     */
    public function __construct(private readonly VideoViewsRepositoryInterface $views_repository)
    {
    }

    /**
     * Purge every bucket older than the retention window.
     *
     * @return int The number of rows deleted.
     */
    public function purge(): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * self::SECONDS_PER_DAY);

        return $this->views_repository->purge_before($cutoff);
    }
}
