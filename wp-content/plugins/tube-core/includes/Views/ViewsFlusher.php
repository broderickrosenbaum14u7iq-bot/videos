<?php
/**
 * Flushes buffered view counts into MySQL. Backs `wp tube-core views:flush`.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Views;

use Tube_Core\Views\Repositories\VideoStatisticsRepositoryInterface;
use Tube_Core\Views\Repositories\VideoViewsRepositoryInterface;

/**
 * Flushes buffered view counts into MySQL — the pure logic behind
 * `wp tube-core views:flush` (ARCHITECTURE.md §7, run every minute).
 *
 * Takes everything currently buffered in Redis, records it against the
 * current hour bucket in wp_tube_video_views, and adds it to each
 * video's running views_total in wp_tube_video_statistics — both in one
 * multi-row write each (ARCHITECTURE.md §19.8), not a loop.
 */
final class ViewsFlusher
{
    /**
     * Construct around the counter this flushes and the repositories it writes to.
     *
     * @param ViewCounterInterface               $counter                Source of buffered view counts.
     * @param VideoViewsRepositoryInterface      $views_repository       Where hour-bucketed counts land.
     * @param VideoStatisticsRepositoryInterface $statistics_repository  Where running totals land.
     */
    public function __construct(
        private readonly ViewCounterInterface $counter,
        private readonly VideoViewsRepositoryInterface $views_repository,
        private readonly VideoStatisticsRepositoryInterface $statistics_repository
    ) {
    }

    /**
     * Flush the buffer.
     *
     * @return int The number of distinct videos flushed (0 if nothing was buffered).
     */
    public function flush(): int
    {
        $counts = $this->counter->flush();

        if ([] === $counts) {
            return 0;
        }

        $view_hour = gmdate('Y-m-d H:00:00');

        $this->views_repository->bulk_record($counts, $view_hour);
        $this->statistics_repository->bump_totals($counts);

        return count($counts);
    }
}
