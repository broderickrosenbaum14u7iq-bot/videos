<?php
/**
 * Integration tests for tube_search_recently_added() against real data.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Tests\Integration\Discovery;

use PHPUnit\Framework\TestCase;
use Tube_Cache\Plugin as Tube_Cache_Plugin;

/**
 * Exercises `tube_search_recently_added()` against real published-video
 * ordering, and proves publishing a new video purges the real
 * Redis-backed "Recently Added" cache entry.
 */
final class RecentlyAddedIntegrationTest extends TestCase
{
    /**
     * Video posts created by a test, cleaned up in tearDown() regardless of outcome.
     *
     * @var int[]
     */
    private array $created_video_ids = [];

    /**
     * Delete every video post created by the test, and clear the "Recently Added" cache.
     */
    protected function tearDown(): void
    {
        foreach ($this->created_video_ids as $video_id) {
            wp_delete_post($video_id, true);
        }

        Tube_Cache_Plugin::instance()->cache()->delete('recently_added');

        $this->created_video_ids = [];
    }

    /**
     * More recently published videos are returned before older ones.
     */
    public function test_returns_the_most_recently_published_videos_first(): void
    {
        $older = $this->create_published_video_at('Recently Added Older Video', '2020-01-01 00:00:00');
        $newer = $this->create_published_video_at('Recently Added Newer Video', '2020-01-02 00:00:00');

        $result    = tube_search_recently_added(50);
        $video_ids = array_map(static fn ($row): int => $row->video_id, $result);

        $older_position = array_search($older, $video_ids, true);
        $newer_position = array_search($newer, $video_ids, true);

        self::assertNotFalse($older_position);
        self::assertNotFalse($newer_position);
        self::assertLessThan($older_position, $newer_position, 'The newer video should rank before the older one.');
    }

    /**
     * Publishing a new video purges the cached "Recently Added" listing.
     */
    public function test_publishing_a_video_purges_the_cached_listing(): void
    {
        tube_search_recently_added();

        self::assertIsArray(Tube_Cache_Plugin::instance()->cache()->get('recently_added'));

        $this->create_published_video('Recently Added Purge Trigger Video');

        self::assertNull(Tube_Cache_Plugin::instance()->cache()->get('recently_added'));
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

    /**
     * Create a real published video post with an explicit publish date, tracked for teardown.
     *
     * @param string $title       The post title.
     * @param string $date_gmt    MySQL DATETIME string for post_date/post_date_gmt.
     */
    private function create_published_video_at(string $title, string $date_gmt): int
    {
        $video_id = wp_insert_post(
            [
                'post_type'     => 'video',
                'post_title'    => $title,
                'post_status'   => 'publish',
                'post_date'     => $date_gmt,
                'post_date_gmt' => $date_gmt,
            ],
            true
        );

        self::assertIsInt($video_id);
        $this->created_video_ids[] = $video_id;

        return $video_id;
    }
}
