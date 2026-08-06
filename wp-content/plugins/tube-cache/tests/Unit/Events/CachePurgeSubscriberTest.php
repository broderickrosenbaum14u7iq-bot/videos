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
     * Purging a video deletes exactly that video's detail and related-videos keys.
     */
    public function test_purge_video_deletes_the_video_detail_and_related_videos_keys(): void
    {
        $this->subscriber->purge_video(42);

        self::assertSame(
            [CacheKeys::video_detail(42), CacheKeys::related_videos(42)],
            $this->cache->deleted
        );
    }

    /**
     * The published handler purges the payload video's own keys, plus
     * the site-wide "Recently Added" listing.
     */
    public function test_handle_video_published_purges_the_payload_video_and_recently_added(): void
    {
        $this->subscriber->handle_video_published(['video_id' => 7]);

        self::assertSame(
            [CacheKeys::video_detail(7), CacheKeys::related_videos(7), CacheKeys::recently_added()],
            $this->cache->deleted
        );
    }

    /**
     * Even with a malformed payload, "Recently Added" is still purged —
     * it doesn't need a video_id to know it might be stale (the handler
     * still fired, so *something* was published).
     */
    public function test_handle_video_published_purges_recently_added_even_with_a_malformed_payload(): void
    {
        $this->subscriber->handle_video_published([]);

        self::assertSame([CacheKeys::recently_added()], $this->cache->deleted);
    }

    /**
     * The updated handler purges only the payload video's own keys.
     */
    public function test_handle_video_updated_purges_the_payload_video(): void
    {
        $this->subscriber->handle_video_updated(['video_id' => 8]);

        self::assertSame(
            [CacheKeys::video_detail(8), CacheKeys::related_videos(8)],
            $this->cache->deleted
        );
    }

    /**
     * The deleted handler purges the payload video's own keys, plus the
     * site-wide "Trending"/"Most Viewed" listings.
     */
    public function test_handle_video_deleted_purges_the_payload_video_and_trending_most_viewed(): void
    {
        $this->subscriber->handle_video_deleted(['video_id' => 9]);

        self::assertSame(
            [
                CacheKeys::video_detail(9),
                CacheKeys::related_videos(9),
                CacheKeys::trending(),
                CacheKeys::most_viewed(),
            ],
            $this->cache->deleted
        );
    }

    /**
     * The stats-rolled-up handler purges only "Trending"/"Most Viewed" —
     * never an individual video's own cache entry, per ARCHITECTURE.md §16.1.
     */
    public function test_handle_video_stats_rolled_up_purges_only_trending_and_most_viewed(): void
    {
        $this->subscriber->handle_video_stats_rolled_up(
            [
                'video_id'    => 5,
                'views_total' => 100,
            ]
        );

        self::assertSame([CacheKeys::trending(), CacheKeys::most_viewed()], $this->cache->deleted);
    }

    /**
     * The stream-status-changed handler purges only the payload video's
     * own detail key — not related-videos, not any listing key — per
     * ARCHITECTURE.md §16.1's row for this event. Added Phase 11: this
     * event previously had no subscriber at all.
     */
    public function test_handle_video_stream_status_changed_purges_only_the_video_detail_key(): void
    {
        $this->subscriber->handle_video_stream_status_changed(
            [
                'video_id' => 11,
                'status'   => 'ready',
            ]
        );

        self::assertSame([CacheKeys::video_detail(11)], $this->cache->deleted);
    }

    /**
     * A malformed payload is ignored rather than throwing, same as every other handler.
     */
    public function test_handle_video_stream_status_changed_ignores_a_malformed_payload(): void
    {
        $this->subscriber->handle_video_stream_status_changed([]);

        self::assertSame([], $this->cache->deleted);
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
     * A payload missing `video_id` skips the video-specific purge rather than throwing.
     */
    public function test_handler_ignores_a_payload_missing_video_id(): void
    {
        $this->subscriber->handle_video_updated([]);

        self::assertSame([], $this->cache->deleted);
    }

    /**
     * A payload with a non-numeric `video_id` skips the video-specific purge rather than throwing.
     */
    public function test_handler_ignores_a_payload_with_a_non_numeric_video_id(): void
    {
        $this->subscriber->handle_video_updated(['video_id' => 'not-a-number']);

        self::assertSame([], $this->cache->deleted);
    }
}
