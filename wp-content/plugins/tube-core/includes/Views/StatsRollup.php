<?php
/**
 * Recomputes views_today/views_7d/views_30d for every video. Backs `wp tube-core stats:rollup`.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Views;

use Tube_Core\Events\Dispatcher;
use Tube_Core\Events\EventCatalog;
use Tube_Core\Views\Repositories\VideoStatisticsRepositoryInterface;
use Tube_Core\Views\Repositories\VideoViewsRepositoryInterface;

/**
 * Recomputes views_today/views_7d/views_30d for every video — the pure
 * logic behind `wp tube-core stats:rollup` (ARCHITECTURE.md §7, every 5
 * minutes) and `wp tube-core stats:rollup --full` (nightly).
 *
 * Both cadences run the exact same full recompute — at this project's
 * real target scale (3,000–10,000 videos), a single indexed aggregate
 * query over every video every 5 minutes is cheap enough that tracking
 * "which videos changed since the last run" separately would be real,
 * unjustified complexity for no measurable benefit, exactly the kind of
 * enterprise pattern this phase is scoped to avoid. `--full` exists as a
 * distinct WP-CLI subcommand only because ARCHITECTURE.md §7's crontab
 * lists it as a separate nightly cron entry; there is nothing for it to
 * do differently at this scale.
 *
 * Every video with a statistics row gets its windows recomputed on every
 * run — including videos with zero views in the last 30 days, so a
 * stale non-zero value is never left displayed. `views_total` is
 * untouched here (it is a running counter, see `ViewsFlusher`) — only
 * the three window columns.
 */
final class StatsRollup
{
    /**
     * A literal 86400, not WordPress's DAY_IN_SECONDS — this class is
     * deliberately WordPress-independent (see this file's own docblock
     * on why it's unit-tested without WordPress loaded), and
     * DAY_IN_SECONDS is a WordPress-defined constant, not a PHP one.
     */
    private const SECONDS_PER_DAY = 86400;

    /**
     * Construct around the repositories this reads from/writes to and
     * the dispatcher VIDEO_STATS_ROLLED_UP goes through.
     *
     * @param VideoViewsRepositoryInterface      $views_repository      Source of window sums.
     * @param VideoStatisticsRepositoryInterface $statistics_repository Where recomputed windows land.
     * @param Dispatcher                         $dispatcher            Announces each video's updated stats.
     */
    public function __construct(
        private readonly VideoViewsRepositoryInterface $views_repository,
        private readonly VideoStatisticsRepositoryInterface $statistics_repository,
        private readonly Dispatcher $dispatcher
    ) {
    }

    /**
     * Recompute every video's windows.
     *
     * @return int The number of videos updated.
     */
    public function rollup(): int
    {
        $totals = $this->statistics_repository->all_totals();

        if ([] === $totals) {
            return 0;
        }

        $sums = $this->views_repository->window_sums(
            gmdate('Y-m-d 00:00:00'),
            gmdate('Y-m-d H:i:s', time() - 7 * self::SECONDS_PER_DAY),
            gmdate('Y-m-d H:i:s', time() - 30 * self::SECONDS_PER_DAY)
        );

        $windows = [];

        foreach (array_keys($totals) as $video_id) {
            $windows[ $video_id ] = $sums[ $video_id ] ?? [
                'today' => 0,
                'd7'    => 0,
                'd30'   => 0,
            ];
        }

        $this->statistics_repository->update_windows($windows);

        foreach ($windows as $video_id => $window) {
            $this->dispatcher->dispatch(
                EventCatalog::VIDEO_STATS_ROLLED_UP,
                [
                    'video_id'    => $video_id,
                    'views_total' => $totals[ $video_id ],
                ]
            );
        }

        return count($windows);
    }
}
