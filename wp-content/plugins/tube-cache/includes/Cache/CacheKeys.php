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
}
