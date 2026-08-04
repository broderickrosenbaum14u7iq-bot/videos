<?php
/**
 * Contract for wp_tube_video_statistics data access.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Views\Repositories;

/**
 * Contract for wp_tube_video_statistics data access, per the
 * `{Noun}Repository` convention (ARCHITECTURE.md §19.4).
 *
 * `views_total` is a running counter (see self::bump_totals()), never
 * recomputed from wp_tube_video_views — that table has a retention
 * window, so summing it can never correctly reconstruct a true all-time
 * total. `views_today`/`views_7d`/`views_30d` are always recomputed
 * fresh (see self::update_windows()), since those windows are always
 * within the retention period.
 */
interface VideoStatisticsRepositoryInterface
{
    /**
     * Add newly-flushed view counts to each video's running
     * `views_total`, one multi-row `INSERT ... ON DUPLICATE KEY UPDATE`
     * (ARCHITECTURE.md §19.8), creating the row (with `views_today`/
     * `views_7d`/`views_30d` starting at 0) if a video is being counted
     * for the first time. Those window columns being briefly 0 for a
     * brand-new row until the next `stats:rollup` run is the same
     * bounded, eventually-consistent tradeoff ARCHITECTURE.md §16.2
     * already accepts for trending data generally.
     *
     * @param array<int, int> $counts Video ID => view count to add to views_total.
     */
    public function bump_totals(array $counts): void;

    /**
     * Every video's current running `views_total` — including videos
     * with zero recent activity, so a caller can zero out their window
     * columns rather than leaving them stale, and can dispatch
     * `VIDEO_STATS_ROLLED_UP` (which carries `views_total`) without a
     * second query.
     *
     * @return array<int, int> Video ID => current views_total.
     */
    public function all_totals(): array;

    /**
     * Overwrite `views_today`/`views_7d`/`views_30d` for every video
     * given, one multi-row `INSERT ... ON DUPLICATE KEY UPDATE`
     * (ARCHITECTURE.md §19.8).
     *
     * @param array<int, array{today: int, d7: int, d30: int}> $windows Video ID => window values.
     */
    public function update_windows(array $windows): void;

    /**
     * The videos with the highest all-time `views_total`, per
     * ARCHITECTURE.md §12 Phase 7's "Most Viewed" ("read only from the
     * precomputed statistics table") — an indexed `ORDER BY ... LIMIT`
     * against `views_total_idx`, not a runtime aggregation.
     *
     * @param int $limit Maximum number of videos to return.
     *
     * @return list<array{video_id: int, count: int}> Highest `views_total` first.
     */
    public function top_by_views_total(int $limit): array;

    /**
     * The videos with the highest 7-day `views_7d`, per ARCHITECTURE.md
     * §12 Phase 7's "Trending" ("use the existing statistics table, no
     * runtime aggregation") — an indexed `ORDER BY ... LIMIT` against
     * `views_7d_idx`.
     *
     * @param int $limit Maximum number of videos to return.
     *
     * @return list<array{video_id: int, count: int}> Highest `views_7d` first.
     */
    public function top_by_views_7d(int $limit): array;
}
