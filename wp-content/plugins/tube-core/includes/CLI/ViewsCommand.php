<?php
/**
 * `wp tube-core views:flush` / `stats:rollup` / `views:partition-maintenance`.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\CLI;

use Tube_Core\Views\Retention;
use Tube_Core\Views\StatsRollup;
use Tube_Core\Views\ViewsFlusher;
use WP_CLI;

/**
 * `wp tube-core views:flush` / `stats:rollup` / `views:partition-maintenance`
 * — the three Linux-cron-driven jobs ARCHITECTURE.md §7/§12 Phase 4
 * assigns to tube-core's view-tracking feature.
 *
 * Registered as three individually-named commands (not one class with
 * WP-CLI's usual space-separated subcommands) specifically to match
 * ARCHITECTURE.md §7's literal, colon-containing command names exactly —
 * see `Plugin::register_cli_commands()`.
 */
final class ViewsCommand
{
    /**
     * Construct around the jobs this command runs.
     *
     * @param ViewsFlusher $flusher   Backs `views:flush`.
     * @param StatsRollup  $rollup    Backs `stats:rollup`.
     * @param Retention    $retention Backs `views:partition-maintenance`.
     */
    public function __construct(
        private readonly ViewsFlusher $flusher,
        private readonly StatsRollup $rollup,
        private readonly Retention $retention
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
}
