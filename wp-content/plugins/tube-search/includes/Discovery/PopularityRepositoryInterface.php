<?php
/**
 * Contract for reading ranked view-count data for "Trending"/"Most Viewed".
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Discovery;

/**
 * Contract for reading ranked view-count data for "Trending"/"Most
 * Viewed", per ARCHITECTURE.md §12 Phase 7 ("use the existing
 * statistics table... no runtime aggregation").
 *
 * Tube-search's own interface, decoupled from tube-core's
 * `VideoStatisticsRepositoryInterface` — the same reasoning
 * `Tube_Search\Cache\SearchCacheInterface` documents for tube-cache:
 * this plugin's own PHPUnit suite has no dependency on tube-core's
 * package (DEVELOPMENT_RULES.md §2), so `PopularVideosQuery` (the class
 * that needs a real test fake for this) can't type-hint tube-core's
 * interface directly. `TubeCorePopularityRepository` is the one thin,
 * WordPress/tube-core-coupled implementation.
 *
 * Adopted per the interface-justification rule (§19.1).
 */
interface PopularityRepositoryInterface
{
    /**
     * The videos with the highest all-time view count — "Most Viewed".
     *
     * @param int $limit Maximum number of videos to return.
     *
     * @return list<array{video_id: int, count: int}> Highest first.
     */
    public function top_by_total(int $limit): array;

    /**
     * The videos with the highest recent (7-day) view count — "Trending".
     *
     * @param int $limit Maximum number of videos to return.
     *
     * @return list<array{video_id: int, count: int}> Highest first.
     */
    public function top_by_recent(int $limit): array;
}
