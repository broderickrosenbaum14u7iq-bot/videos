<?php
/**
 * Documented cache-key naming convention.
 *
 * @package Tube_Cache
 */

declare(strict_types=1);

namespace Tube_Cache\Cache;

/**
 * Documented cache-key naming convention.
 *
 * Establishing this now, in Phase 3, is what lets CachePurgeSubscriber
 * purge correctly the moment a real consumer (tube-player, Phase 6)
 * starts calling CacheInterface::set() for a video's detail lookup — the
 * key a future writer uses and the key today's purge subscriber deletes
 * are the same because both go through this one place, not because the
 * string happens to match by convention.
 */
final class CacheKeys
{
    /**
     * The cache key for a single video's detail lookup.
     *
     * @param int $video_id The video post ID.
     */
    public static function video_detail(int $video_id): string
    {
        return 'video_detail:' . $video_id;
    }

    /**
     * The cache key for one video's related-videos list
     * (`Tube_Search\Discovery\RelatedVideosFinder`, Phase 7).
     *
     * @param int $video_id The video the related list is for.
     */
    public static function related_videos(int $video_id): string
    {
        return 'related_videos:' . $video_id;
    }

    /**
     * The cache key for the site-wide "Trending" listing
     * (`Tube_Search\Discovery\PopularVideosQuery::trending()`, Phase 7).
     */
    public static function trending(): string
    {
        return 'trending';
    }

    /**
     * The cache key for the site-wide "Most Viewed" listing
     * (`Tube_Search\Discovery\PopularVideosQuery::most_viewed()`, Phase 7).
     */
    public static function most_viewed(): string
    {
        return 'most_viewed';
    }

    /**
     * The cache key for the site-wide "Recently Added" listing
     * (`Tube_Search\Discovery\RecentlyAddedQuery`, Phase 7).
     */
    public static function recently_added(): string
    {
        return 'recently_added';
    }

    /**
     * The cache key for one search query's results page
     * (`Tube_Search\Search\SearchQuery`, Phase 7).
     *
     * Never proactively purged (ARCHITECTURE.md §16.2's deep-page
     * philosophy applies directly: the key space is unbounded — any
     * video change could affect results for some arbitrary query text —
     * so this is deliberately not in the purge table below; it's bounded
     * by a short TTL instead, applied where it's set.
     *
     * @param string $query    The raw search query text.
     * @param int    $page     The results page number.
     * @param int    $per_page Results per page.
     */
    public static function search(string $query, int $page, int $per_page): string
    {
        return 'search:' . md5($query) . ':' . $page . ':' . $per_page;
    }
}
