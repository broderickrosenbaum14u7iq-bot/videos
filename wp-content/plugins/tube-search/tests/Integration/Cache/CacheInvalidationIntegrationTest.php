<?php
/**
 * Integration tests for cache invalidation on video deletion.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Tests\Integration\Cache;

use PHPUnit\Framework\TestCase;
use Tube_Cache\Plugin as Tube_Cache_Plugin;

/**
 * Proves the real `tube_core.video.deleted` event purges every
 * tube-search cache key `Tube_Cache\Events\CachePurgeSubscriber::
 * handle_video_deleted()` documents it should — the real `add_action()`
 * wiring end-to-end, against real Redis, not the fake-backed decision
 * logic already covered by tube-cache's own unit suite. Publish-time
 * ("Recently Added") and stats-rollup-time ("Trending"/"Most Viewed")
 * purges are covered by `RecentlyAddedIntegrationTest`/
 * `PopularVideosIntegrationTest` instead, alongside the feature they
 * actually affect.
 */
final class CacheInvalidationIntegrationTest extends TestCase
{
    /**
     * Video posts created by a test, cleaned up in tearDown() regardless of outcome.
     *
     * @var int[]
     */
    private array $created_video_ids = [];

    /**
     * Delete every remaining video post created by the test, and any cache keys it may have touched.
     */
    protected function tearDown(): void
    {
        foreach ($this->created_video_ids as $video_id) {
            wp_delete_post($video_id, true);
            Tube_Cache_Plugin::instance()->cache()->delete('related_videos:' . $video_id);
        }

        Tube_Cache_Plugin::instance()->cache()->delete('trending');
        Tube_Cache_Plugin::instance()->cache()->delete('most_viewed');

        $this->created_video_ids = [];
    }

    /**
     * Deleting a video purges its own related-videos entry, plus the site-wide trending/most-viewed listings.
     */
    public function test_deleting_a_video_purges_related_videos_trending_and_most_viewed(): void
    {
        $video_id = $this->create_published_video('Cache Invalidation Delete Video');

        $cache = Tube_Cache_Plugin::instance()->cache();
        $cache->set('related_videos:' . $video_id, ['stale' => true], 300);
        $cache->set('trending', ['stale' => true], 300);
        $cache->set('most_viewed', ['stale' => true], 300);

        wp_delete_post($video_id, true);
        $this->created_video_ids = array_diff($this->created_video_ids, [$video_id]);

        self::assertNull($cache->get('related_videos:' . $video_id));
        self::assertNull($cache->get('trending'));
        self::assertNull($cache->get('most_viewed'));
    }

    /**
     * Deleting one video's cache entries never touches a different, still-cached video's own entry.
     */
    public function test_deleting_one_video_does_not_purge_another_videos_cache_entry(): void
    {
        $deleted_id  = $this->create_published_video('Cache Invalidation Deleted Video');
        $survivor_id = $this->create_published_video('Cache Invalidation Survivor Video');

        $cache = Tube_Cache_Plugin::instance()->cache();
        $cache->set('related_videos:' . $deleted_id, ['stale' => true], 300);
        $cache->set('related_videos:' . $survivor_id, ['still_fresh' => true], 300);

        wp_delete_post($deleted_id, true);
        $this->created_video_ids = array_diff($this->created_video_ids, [$deleted_id]);

        self::assertNull($cache->get('related_videos:' . $deleted_id));
        self::assertSame(['still_fresh' => true], $cache->get('related_videos:' . $survivor_id));
    }

    /**
     * Create a real published video post, tracked for teardown.
     *
     * @param string $title The post title.
     */
    private function create_published_video(string $title): int
    {
        $video_id = wp_insert_post(
            [
                'post_type'   => 'video',
                'post_title'  => $title,
                'post_status' => 'publish',
            ],
            true
        );

        self::assertIsInt($video_id);
        $this->created_video_ids[] = $video_id;

        return $video_id;
    }
}
