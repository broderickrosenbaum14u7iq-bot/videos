<?php
/**
 * `wp tube-core views:flush` / `stats:rollup` / `views:partition-maintenance`.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\CLI;

use Tube_Core\Content\VideoPostType;
use Tube_Core\Views\Repositories\VideoStatisticsRepositoryInterface;
use Tube_Core\Views\Retention;
use Tube_Core\Views\StatsRollup;
use Tube_Core\Views\ViewBaselineSubscriber;
use Tube_Core\Views\ViewsFlusher;
use WP_CLI;
use WP_Query;

/**
 * `wp tube-core views:flush` / `stats:rollup` / `views:partition-maintenance` /
 * `views:seed-baseline` — the three Linux-cron-driven jobs ARCHITECTURE.md
 * §7/§12 Phase 4 assigns to tube-core's view-tracking feature, plus the
 * one-off/re-runnable baseline backfill (2026-08-25, alongside the real
 * view-recording fix).
 *
 * Registered as individually-named commands (not one class with WP-CLI's
 * usual space-separated subcommands) specifically to match
 * ARCHITECTURE.md §7's literal, colon-containing command names exactly —
 * see `Plugin::register_cli_commands()`.
 */
final class ViewsCommand
{
    /**
     * How many videos to fetch per `WP_Query` page while seeding the
     * baseline — the same bounded-batch shape
     * `Tube_Search\CLI\IndexCommand::rebuild()` already established, so a
     * large catalog is never loaded into memory at once.
     */
    private const SEED_BASELINE_BATCH_SIZE = 200;

    /**
     * Construct around the jobs this command runs.
     *
     * @param ViewsFlusher                       $flusher               Backs `views:flush`.
     * @param StatsRollup                        $rollup                Backs `stats:rollup`.
     * @param Retention                          $retention             Backs `views:partition-maintenance`.
     * @param VideoStatisticsRepositoryInterface $statistics_repository Backs `views:seed-baseline`.
     */
    public function __construct(
        private readonly ViewsFlusher $flusher,
        private readonly StatsRollup $rollup,
        private readonly Retention $retention,
        private readonly VideoStatisticsRepositoryInterface $statistics_repository
    ) {
    }

    /**
     * Flush buffered Redis view counts into wp_tube_video_views and
     * wp_tube_video_statistics.
     *
     * ## EXAMPLES
     *
     *     wp tube-core views:flush
     *
     * @when after_wp_load
     */
    public function flush(): void
    {
        $count = $this->flusher->flush();

        if (0 === $count) {
            WP_CLI::log('Nothing buffered to flush.');

            return;
        }

        WP_CLI::success(sprintf('Flushed buffered views for %d video(s).', $count));
    }

    /**
     * Recompute views_today/views_7d/views_30d for every video.
     *
     * ## OPTIONS
     *
     * [--full]
     * : Present for parity with ARCHITECTURE.md §7's nightly cron entry.
     * At this project's real target scale a full recompute is already
     * cheap enough to run every 5 minutes regardless — see StatsRollup's
     * own docblock — so this flag does not change what runs, only what
     * gets logged.
     *
     * ## EXAMPLES
     *
     *     wp tube-core stats:rollup
     *     wp tube-core stats:rollup --full
     *
     * @when after_wp_load
     *
     * @param array<int, string>    $args       Positional arguments (unused).
     * @param array<string, string> $assoc_args Associative arguments (--full).
     */
    public function rollup(array $args, array $assoc_args): void
    {
        $count = $this->rollup->rollup();
        $label = isset($assoc_args['full']) ? ' (full)' : '';

        if (0 === $count) {
            WP_CLI::log("No videos have any recorded views yet{$label}.");

            return;
        }

        WP_CLI::success(sprintf('Recomputed statistics for %d video(s)%s.', $count, $label));
    }

    /**
     * Purge wp_tube_video_views rows older than the retention window.
     *
     * ## EXAMPLES
     *
     *     wp tube-core views:partition-maintenance
     *
     * @when after_wp_load
     */
    public function partition_maintenance(): void
    {
        $deleted = $this->retention->purge();

        WP_CLI::success(sprintf('Purged %d row(s) older than the retention window.', $deleted));
    }

    /**
     * Seed `wp_tube_video_statistics.views_total` with the baseline
     * (`ViewBaselineSubscriber::BASELINE_VIEWS`) for every published
     * video that doesn't have a statistics row yet — the safe way to
     * apply the baseline to videos published before
     * `ViewBaselineSubscriber` existed (that subscriber now seeds it
     * automatically for every video at the moment it's published, so
     * this command's real job is a one-time catch-up for the existing
     * catalog; safely re-runnable afterward, since
     * `VideoStatisticsRepositoryInterface::ensure_baseline()` never
     * touches a row that already exists — including one a real view has
     * already incremented past the baseline).
     *
     * ## EXAMPLES
     *
     *     wp tube-core views:seed-baseline
     *
     * @when after_wp_load
     */
    public function seed_baseline(): void
    {
        $seeded = 0;
        $paged  = 1;

        do {
            $query = new WP_Query(
                [
                    'post_type'              => VideoPostType::POST_TYPE,
                    'post_status'            => 'publish',
                    'fields'                 => 'ids',
                    'posts_per_page'         => self::SEED_BASELINE_BATCH_SIZE,
                    'paged'                  => $paged,
                    'orderby'                => 'ID',
                    'order'                  => 'ASC',
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                ]
            );

            $batch_ids  = array_map(static fn (mixed $id): int => is_scalar($id) ? (int) $id : 0, $query->posts);
            $batch_size = count($batch_ids);

            foreach ($batch_ids as $video_id) {
                $this->statistics_repository->ensure_baseline($video_id, ViewBaselineSubscriber::BASELINE_VIEWS);
                ++$seeded;
            }

            ++$paged;
        } while (self::SEED_BASELINE_BATCH_SIZE === $batch_size);

        WP_CLI::success(
            sprintf(
                'Checked %d published video(s) — each now has a statistics row seeded at %d if it didn\'t'
                    . ' already have one.',
                $seeded,
                ViewBaselineSubscriber::BASELINE_VIEWS
            )
        );
    }
}
