<?php
/**
 * Contract for the write/sync side of wp_tube_search_index data access.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Index;

/**
 * Contract for the write/sync side of `wp_tube_search_index` data
 * access, per the `{Noun}Repository` convention (ARCHITECTURE.md §19.4).
 * Split from `DiscoveryRepositoryInterface` (the read side) because the
 * two have genuinely different consumers: this one is used only by
 * `Tube_Search\Events\SearchIndexSyncSubscriber` and
 * `Tube_Search\CLI\IndexCommand` (via `Tube_Search\Index\VideoIndexer`),
 * never by a discovery/query class — keeping them separate means those
 * query classes can't accidentally write to the index they only read from.
 *
 * `SearchIndexRepository` implements both interfaces (one table, one
 * `$wpdb` connection, no reason to duplicate that across two classes).
 */
interface SearchIndexRepositoryInterface
{
    /**
     * Insert or fully overwrite one video's index row.
     *
     * @param int         $video_id         The video post ID.
     * @param string      $title            The video's title.
     * @param string|null $description      The video's description/excerpt, if any.
     * @param int[]       $category_ids     `video_category` term IDs.
     * @param int[]       $tag_ids          `video_tag` term IDs.
     * @param int[]       $actor_ids        Actor IDs (empty until tube-admin, Phase 10, assigns any).
     * @param int[]       $studio_ids       Studio IDs (empty until tube-admin, Phase 10, assigns any).
     * @param int|null    $duration_seconds The video's duration, if known.
     * @param int         $views_total      The video's current all-time view count.
     * @param string|null $published_at     MySQL `DATETIME` string, or null if never published.
     */
    public function upsert(
        int $video_id,
        string $title,
        ?string $description,
        array $category_ids,
        array $tag_ids,
        array $actor_ids,
        array $studio_ids,
        ?int $duration_seconds,
        int $views_total,
        ?string $published_at
    ): void;

    /**
     * Overwrite one video's denormalized `views_total`, without touching
     * any other column — the cheap path `tube_core.video.stats_rolled_up`
     * uses, avoiding a full re-denormalization on every 5-minute rollup.
     *
     * @param int $video_id    The video post ID.
     * @param int $views_total The video's current all-time view count.
     */
    public function update_views_total(int $video_id, int $views_total): void;

    /**
     * Overwrite one video's `duration_seconds`, without touching any
     * other column — the cheap path
     * `tube_core.video.stream_status_changed` uses.
     *
     * @param int $video_id         The video post ID.
     * @param int $duration_seconds The video's now-known duration.
     */
    public function update_duration(int $video_id, int $duration_seconds): void;

    /**
     * Remove one video's index row entirely.
     *
     * @param int $video_id The video post ID.
     */
    public function delete(int $video_id): void;

    /**
     * Every currently-indexed video ID — `Tube_Search\CLI\IndexCommand`'s
     * `index:rebuild` uses this to find and remove drift: any indexed
     * video that's no longer published (deleted, unpublished, or its
     * `video.deleted` event was somehow missed) is never re-added by a
     * rebuild's own publish-status query, so comparing this list against
     * the current published set is what "corrects any drift" (§2.6)
     * actually means for removals, not just additions/updates.
     *
     * @return int[]
     */
    public function all_video_ids(): array;
}
