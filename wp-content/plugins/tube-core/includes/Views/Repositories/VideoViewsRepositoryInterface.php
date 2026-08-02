<?php
/**
 * Contract for wp_tube_video_views data access.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Views\Repositories;

/**
 * Contract for wp_tube_video_views data access, per the
 * `{Noun}Repository` convention (ARCHITECTURE.md §19.4).
 *
 * Adopted per the interface-justification rule (§19.1): the real payoff
 * is a test fake `ViewsFlusher`, `StatsRollup`, and `Retention` are each
 * actually unit-tested against, without a live database.
 */
interface VideoViewsRepositoryInterface
{
    /**
     * Add buffered view counts to their hour bucket, one multi-row
     * `INSERT ... ON DUPLICATE KEY UPDATE` (ARCHITECTURE.md §19.8 —
     * never a loop of single-row writes), creating the (video, hour) row
     * if it doesn't exist yet or adding to it if it does.
     *
     * @param array<int, int> $counts    Video ID => view count to add, for this one hour bucket.
     * @param string          $view_hour MySQL `DATETIME` string truncated to the hour (e.g. `2026-08-02 14:00:00`).
     */
    public function bulk_record(array $counts, string $view_hour): void;

    /**
     * Sum view counts into three overlapping windows, in one query — a
     * video with zero views in the last 30 days is simply absent from
     * the result, not present with zeros (callers that need every video
     * represented, e.g. to zero out a stale row, get the full video list
     * from elsewhere and default missing entries to zero themselves).
     *
     * @param string $today_start     MySQL `DATETIME` string: start of "today" (inclusive).
     * @param string $seven_days_ago  MySQL `DATETIME` string: seven-day window start (inclusive).
     * @param string $thirty_days_ago MySQL `DATETIME` string: thirty-day window
     *                                start (inclusive). Must be the earliest of
     *                                the three — it also bounds the query's own
     *                                scan range.
     *
     * @return array<int, array{today: int, d7: int, d30: int}> Video ID => window
     *         sums, only for videos with at least one view since $thirty_days_ago.
     */
    public function window_sums(string $today_start, string $seven_days_ago, string $thirty_days_ago): array;

    /**
     * Delete every row older than $cutoff (retention).
     *
     * @param string $cutoff MySQL `DATETIME` string — rows with `view_hour` strictly before this are deleted.
     *
     * @return int Number of rows deleted.
     */
    public function purge_before(string $cutoff): int;
}
