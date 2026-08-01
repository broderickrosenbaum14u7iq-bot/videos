<?php
/**
 * Unit tests for CachePurgeSubscriber.
 *
 * @package Tube_Cache
 */

declare(strict_types=1);

namespace Tube_Cache\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use Tube_Cache\Cache\CacheKeys;
use Tube_Cache\Events\CachePurgeSubscriber;
use Tube_Cache\Tests\Unit\Cache\Fixtures\InMemoryCache;

/**
 * Exercises CachePurgeSubscriber's purge decision logic against a fake
 * CacheInterface — no WordPress, no live Redis, no Tube_Core dependency
 * (see the class's own docblock for why that last one specifically is a
 * deliberate design choice, not an oversight).
 *
 * The register() method itself (the real add_action() wiring) is not
 * exercised here — it is WordPress-hook-signature-coupled, verified live
 * instead, the same split VideoLifecycleEvents already established in
 * tube-core Phase 2. What's tested here is the actual decision logic
 * each handler delegates to.
 */
final class CachePurgeSubscriberTest extends TestCase
{
    /**
     * The fake cache the subscriber under test is wired to.
     *
     * @var InMemoryCache
     */
    private InMemoryCache $cache;

    /**
     * The subscriber under test.
     *
     * @var CachePurgeSubscriber
     */
    private CachePurgeSubscriber $subscriber;

    /**
     * Build a fresh subscriber and fake cache for each test.
     */
    protected function setUp(): void
    {
        $this->cache      = new InMemoryCache();
        $this->subscriber = new CachePurgeSubscriber($this->cache);
    }

    /**
     * Purging a video deletes exactly that video's detail key.
     */
    public function test_purge_video_deletes_the_video_detail_key(): void
    {
        $this->subscriber->purge_video(42);

        self::assertSame([CacheKeys::video_detail(42)], $this->cache->deleted);
    }

    /**
     * The published handler purges the video named in the payload.
     */
    public function test_handle_video_published_purges_the_payload_video(): void
    {
        $this->subscriber->handle_video_published(['video_id' => 7]);

        self::assertSame([CacheKeys::video_detail(7)], $this->cache->deleted);
    }

    /**
     * The updated handler purges the video named in the payload.
     */
    public function test_handle_video_updated_purges_the_payload_video(): void
    {
        $this->subscriber->handle_video_updated(['video_id' => 8]);

        self::assertSame([CacheKeys::video_detail(8)], $this->cache->deleted);
    }

    /**
     * The deleted handler purges the video named in the payload.
     */
    public function test_handle_video_deleted_purges_the_payload_video(): void
    {
        $this->subscriber->handle_video_deleted(['video_id' => 9]);

        self::assertSame([CacheKeys::video_detail(9)], $this->cache->deleted);
    }

    /**
     * Purging one video does not touch a previously-cached different video's entry.
     */
    public function test_purging_one_video_does_not_affect_another(): void
    {
        $this->cache->set(CacheKeys::video_detail(1), ['title' => 'Video One'], 300);
        $this->cache->set(CacheKeys::video_detail(2), ['title' => 'Video Two'], 300);

        $this->subscriber->purge_video(1);

        self::assertNull($this->cache->get(CacheKeys::video_detail(1)));
        self::assertSame(['title' => 'Video Two'], $this->cache->get(CacheKeys::video_detail(2)));
    }

    /**
     * A payload missing `video_id` is ignored rather than thrown out of the handler.
     */
    public function test_handler_ignores_a_payload_missing_video_id(): void
    {
        $this->subscriber->handle_video_published([]);

        self::assertSame([], $this->cache->deleted);
    }

    /**
     * A payload with a non-numeric `video_id` is ignored rather than thrown out of the handler.
     */
    public function test_handler_ignores_a_payload_with_a_non_numeric_video_id(): void
    {
        $this->subscriber->handle_video_updated(['video_id' => 'not-a-number']);

        self::assertSame([], $this->cache->deleted);
    }
}
