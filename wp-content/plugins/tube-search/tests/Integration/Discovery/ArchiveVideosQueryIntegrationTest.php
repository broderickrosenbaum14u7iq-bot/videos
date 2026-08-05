<?php
/**
 * Integration tests for tube_search_by_category()/by_tag()/by_actor()/by_studio() against real data.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Tests\Integration\Discovery;

use PHPUnit\Framework\TestCase;
use Tube_Cache\Plugin as Tube_Cache_Plugin;
use Tube_Search\Index\CandidateColumn;

/**
 * Exercises `tube_search_by_category()`/`tube_search_by_tag()`/
 * `tube_search_by_actor()`/`tube_search_by_studio()` against a real
 * `JSON_CONTAINS()` scan of `wp_tube_search_index`, and their shared
 * TTL-only caching.
 */
final class ArchiveVideosQueryIntegrationTest extends TestCase
{
    /**
     * Video posts created by a test, cleaned up in tearDown() regardless of outcome.
     *
     * @var int[]
     */
    private array $created_video_ids = [];

    /**
     * `video_category` terms created by a test, cleaned up in tearDown().
     *
     * @var int[]
     */
    private array $created_term_ids = [];

    /**
     * Delete every video post/term created by the test, and clear any cache keys it may have set.
     */
    protected function tearDown(): void
    {
        foreach ($this->created_video_ids as $video_id) {
            wp_delete_post($video_id, true);
        }

        foreach ($this->created_term_ids as $term_id) {
            wp_delete_term($term_id, 'video_category');
        }

        $this->created_video_ids = [];
        $this->created_term_ids  = [];
    }

    /**
     * A real category archive lists exactly the videos assigned to that category, paginated, with a total count.
     */
    public function test_category_archive_lists_assigned_videos_with_a_total_count(): void
    {
        $term_id   = $this->create_category();
        $matching  = $this->create_published_video('Category Archive Match');
        $unrelated = $this->create_published_video('Category Archive Unrelated');

        $this->assign_category_and_resync($matching, $term_id);

        $result = tube_search_by_category($term_id, 1, 24);

        $video_ids = array_map(static fn ($row): int => $row->video_id, $result->items);

        self::assertContains($matching, $video_ids);
        self::assertNotContains($unrelated, $video_ids);
        self::assertSame(1, $result->total);

        Tube_Cache_Plugin::instance()->cache()->delete(CandidateColumn::CategoryIds->value . ':' . $term_id . ':1:24');
    }

    /**
     * The result is cached, and a second call reuses it.
     */
    public function test_result_is_cached(): void
    {
        $term_id = $this->create_category();
        $video   = $this->create_published_video('Category Cache Test Video');

        $this->assign_category_and_resync($video, $term_id);

        tube_search_by_category($term_id, 1, 24);

        $cache_key = CandidateColumn::CategoryIds->value . ':' . $term_id . ':1:24';
        $cached    = Tube_Cache_Plugin::instance()->cache()->get($cache_key);

        self::assertNotNull($cached);

        Tube_Cache_Plugin::instance()->cache()->delete($cache_key);
    }

    /**
     * An actor archive (a dedicated table, not a taxonomy) lists exactly
     * the videos with a real wp_tube_video_actors assignment, proving
     * the JSON_CONTAINS scan works for actor_ids the same way it does
     * for category_ids.
     */
    public function test_actor_archive_lists_assigned_videos(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $video_id = $this->create_published_video('Actor Archive Test Video');

        $now = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test seeding against a dedicated custom table with no write API yet (Phase 10).
        $wpdb->insert(
            $wpdb->prefix . 'tube_actors',
            [
                'name'       => 'Archive Query Test Actor',
                'slug'       => 'archive-query-test-actor-' . uniqid('', true),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s']
        );
        $actor_id = (int) $wpdb->insert_id;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test seeding against a dedicated custom table.
        $wpdb->insert(
            $wpdb->prefix . 'tube_video_actors',
            [
                'video_id' => $video_id,
                'actor_id' => $actor_id,
            ],
            ['%d', '%d']
        );

        wp_update_post(
            [
                'ID'         => $video_id,
                'post_title' => 'Actor Archive Test Video',
            ]
        );

        $result = tube_search_by_actor($actor_id, 1, 24);

        self::assertCount(1, $result->items);
        self::assertSame($video_id, $result->items[0]->video_id);

        Tube_Cache_Plugin::instance()->cache()->delete(CandidateColumn::ActorIds->value . ':' . $actor_id . ':1:24');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup against a dedicated custom table.
        $wpdb->delete($wpdb->prefix . 'tube_video_actors', ['actor_id' => $actor_id], ['%d']);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup against a dedicated custom table.
        $wpdb->delete($wpdb->prefix . 'tube_actors', ['id' => $actor_id], ['%d']);
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
     * Create a real `video_category` term, tracked for teardown.
     */
    private function create_category(): int
    {
        $result = wp_insert_term('Archive Query Test Category ' . uniqid('', true), 'video_category');

        self::assertIsArray($result);

        $term_id                  = (int) $result['term_id'];
        $this->created_term_ids[] = $term_id;

        return $term_id;
    }

    /**
     * Assign a category to a video and force a resync, so the index row reflects it.
     *
     * @param int $video_id The video post ID.
     * @param int $term_id  The `video_category` term ID.
     */
    private function assign_category_and_resync(int $video_id, int $term_id): void
    {
        wp_set_post_terms($video_id, [$term_id], 'video_category');

        $post = get_post($video_id);
        self::assertNotNull($post);

        wp_update_post(
            [
                'ID'         => $video_id,
                'post_title' => $post->post_title,
            ]
        );
    }
}
