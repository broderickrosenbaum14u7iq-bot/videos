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
}
