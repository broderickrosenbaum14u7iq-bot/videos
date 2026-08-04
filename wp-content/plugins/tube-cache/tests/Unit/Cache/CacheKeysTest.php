<?php
/**
 * Unit tests for CacheKeys.
 *
 * @package Tube_Cache
 */

declare(strict_types=1);

namespace Tube_Cache\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Tube_Cache\Cache\CacheKeys;

/**
 * Exercises CacheKeys's naming convention — the only thing worth
 * asserting here is that it is stable and unique per video, since every
 * writer and every purger must agree on it independently.
 */
final class CacheKeysTest extends TestCase
{
    /**
     * Each video ID gets its own distinct video_detail() key.
     */
    public function test_video_detail_is_unique_per_video_id(): void
    {
        self::assertNotSame(CacheKeys::video_detail(1), CacheKeys::video_detail(2));
    }

    /**
     * The same video ID always produces the same video_detail() key.
     */
    public function test_video_detail_is_stable(): void
    {
        self::assertSame(CacheKeys::video_detail(42), CacheKeys::video_detail(42));
    }

    /**
     * Each video ID gets its own distinct related_videos() key.
     */
    public function test_related_videos_is_unique_per_video_id(): void
    {
        self::assertNotSame(CacheKeys::related_videos(1), CacheKeys::related_videos(2));
    }

    /**
     * The site-wide listing keys are each stable, fixed strings — no
     * video ID varies them, since they describe a global listing.
     */
    public function test_site_wide_listing_keys_are_stable(): void
    {
        self::assertSame(CacheKeys::trending(), CacheKeys::trending());
        self::assertSame(CacheKeys::most_viewed(), CacheKeys::most_viewed());
        self::assertSame(CacheKeys::recently_added(), CacheKeys::recently_added());
    }

    /**
     * The three site-wide listing keys are distinct from each other —
     * purging one must never accidentally purge another.
     */
    public function test_site_wide_listing_keys_are_distinct_from_each_other(): void
    {
        $keys = [CacheKeys::trending(), CacheKeys::most_viewed(), CacheKeys::recently_added()];

        self::assertSame($keys, array_unique($keys));
    }

    /**
     * A different query, page, or per_page each produce a distinct search() key.
     */
    public function test_search_is_unique_per_query_page_and_per_page(): void
    {
        $base = CacheKeys::search('cats', 1, 20);

        self::assertNotSame($base, CacheKeys::search('dogs', 1, 20));
        self::assertNotSame($base, CacheKeys::search('cats', 2, 20));
        self::assertNotSame($base, CacheKeys::search('cats', 1, 40));
    }

    /**
     * The same query/page/per_page always produces the same search() key.
     */
    public function test_search_is_stable(): void
    {
        self::assertSame(CacheKeys::search('cats', 1, 20), CacheKeys::search('cats', 1, 20));
    }
}
