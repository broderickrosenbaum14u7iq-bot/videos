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

    /**
     * Paged listing of every video's full statistics row, sorted by a
     * caller-chosen column — the read path `tube-admin`'s statistics
     * dashboard (Phase 10) uses to show `views_total`/`views_today`/
     * `views_7d`/`views_30d` together in one sortable table, unlike
     * {@see self::top_by_views_total()}/{@see self::top_by_views_7d()},
     * which each return only the one column they sort by.
     *
     * @param 'views_total'|'views_today'|'views_7d'|'views_30d' $order_by Column to sort by, highest first.
     * @param int                                                $limit    Maximum number of videos to return.
     * @param int                                                $offset   Number of videos to skip, for pagination.
     *
     * @return list<array{video_id: int, views_total: int, views_today: int, views_7d: int, views_30d: int}>
     */
    public function list_all(string $order_by, int $limit, int $offset): array;

    /**
     * Total number of rows in `wp_tube_video_statistics` — pairs with
     * {@see self::list_all()} for pagination totals.
     */
    public function count_all(): int;

    /**
     * This video's current `likes_total` — a single indexed primary-key
     * read, safe to call once per single-video page render at this
     * project's 100k+ daily visits target (the same cost class as the
     * `views_total` read `Tube_Search\Index\SearchIndexRow` already
     * carries for that same page).
     *
     * @param int $video_id The video post ID.
     */
    public function likes_total(int $video_id): int;

    /**
     * Atomically add 1 to a video's `likes_total`
     * (`UPDATE ... SET likes_total = likes_total + 1`, a single indexed
     * row-level update — never a read-then-write). Called by
     * `Tube_Core\Likes\LikeToggleService` only after
     * `Tube_Core\Likes\Repositories\LikeRepositoryInterface::add()`
     * confirms a like row was genuinely, newly inserted, which is what
     * keeps this counter from ever drifting from that table's true row
     * count under a concurrent-toggle race.
     *
     * @param int $video_id The video post ID.
     */
    public function increment_likes(int $video_id): void;

    /**
     * Atomically subtract 1 from a video's `likes_total`, floored at 0.
     * Same call-only-after-a-confirmed-row-change contract as
     * {@see self::increment_likes()}.
     *
     * @param int $video_id The video post ID.
     */
    public function decrement_likes(int $video_id): void;

    /**
     * Ensure a video has a `wp_tube_video_statistics` row, seeded at
     * `$baseline` if it doesn't already have one — an atomic
     * "insert-if-missing" (`INSERT ... ON DUPLICATE KEY UPDATE video_id =
     * video_id`, a genuine no-op update when a row already exists, not a
     * read-then-decide-then-write race). Never lowers or otherwise
     * touches an existing row's `views_total`, including one a real view
     * has already incremented past `$baseline` — this is a floor applied
     * once, at the moment a video first gets a statistics row, not a
     * value re-applied or reconciled against later.
     *
     * @param int $video_id The video post ID.
     * @param int $baseline The `views_total` to seed a brand-new row with.
     */
    public function ensure_baseline(int $video_id, int $baseline): void;
}
