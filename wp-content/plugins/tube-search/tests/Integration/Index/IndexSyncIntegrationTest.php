<?php
/**
 * Integration tests for tube-search's event-driven index sync.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Tests\Integration\Index;

use PHPUnit\Framework\TestCase;
use Tube_Search\Plugin as Tube_Search_Plugin;

/**
 * Exercises `Tube_Search\Events\SearchIndexSyncSubscriber`'s real
 * `add_action()` wiring against real WordPress `wp_insert_post()`/
 * `wp_set_post_terms()`/`wp_delete_post()` calls — proving
 * `wp_tube_search_index` actually stays in sync with the video CPT, not
 * just that `VideoIndexer`'s decision logic is correct in isolation.
 */
final class IndexSyncIntegrationTest extends TestCase
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
     * Delete every video post and term created by the test — VIDEO_DELETED
     * fires for each post, which also removes its index row.
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
     * Publishing a video creates a matching wp_tube_search_index row, categories included.
     */
    public function test_publishing_a_video_indexes_it(): void
    {
        $video_id = $this->create_published_video('Index Sync Test Video');
        $term_id  = $this->create_category('Index Sync Category');

        wp_set_post_terms($video_id, [$term_id], 'video_category');

        // wp_set_post_terms() alone doesn't fire tube-core's VIDEO_UPDATED
        // (that's a save_post_video hook); re-save the post to trigger a resync.
        wp_update_post(
            [
                'ID'         => $video_id,
                'post_title' => 'Index Sync Test Video',
            ]
        );

        $row = Tube_Search_Plugin::instance()->search_index_repository()->find($video_id);

        self::assertNotNull($row);
        self::assertSame('Index Sync Test Video', $row->title);
        self::assertSame([$term_id], $row->category_ids);
    }

    /**
     * Moving a published video to draft removes its index row.
     */
    public function test_unpublishing_a_video_removes_it_from_the_index(): void
    {
        $video_id = $this->create_published_video('Unpublish Sync Test Video');

        self::assertNotNull(Tube_Search_Plugin::instance()->search_index_repository()->find($video_id));

        wp_update_post(
            [
                'ID'          => $video_id,
                'post_status' => 'draft',
            ]
        );

        self::assertNull(Tube_Search_Plugin::instance()->search_index_repository()->find($video_id));
    }

    /**
     * Deleting a published video removes its index row.
     */
    public function test_deleting_a_video_removes_it_from_the_index(): void
    {
        $video_id = $this->create_published_video('Delete Sync Test Video');

        self::assertNotNull(Tube_Search_Plugin::instance()->search_index_repository()->find($video_id));

        wp_delete_post($video_id, true);

        self::assertNull(Tube_Search_Plugin::instance()->search_index_repository()->find($video_id));

        $this->created_video_ids = array_diff($this->created_video_ids, [$video_id]);
    }

    /**
     * A video with no known Cloudflare Stream duration yet (no
     * `wp_tube_video_metadata` row at all — a video published via
     * `Tube_Admin\Video\StreamUidMetaBox` before any Stream UID sync has
     * happened, or a video for which `wp_tube_video_metadata.duration_seconds`
     * is genuinely `NULL`) must index with a `NULL` `duration_seconds`,
     * never `0`. Regression test for a real bug found live: `$wpdb->prepare()`'s
     * `%d` placeholder silently casts `null` to `0`
     * (`SearchIndexRepository::upsert()` used `%d` unconditionally for
     * this column), which the frontend then rendered as a fabricated
     * "0:00" instead of correctly omitting the duration badge for a
     * genuinely-unknown duration.
     */
    public function test_publishing_a_video_with_no_known_duration_indexes_a_null_duration_not_zero(): void
    {
        $video_id = $this->create_published_video('No Duration Yet Sync Test Video');

        $row = Tube_Search_Plugin::instance()->search_index_repository()->find($video_id);

        self::assertNotNull($row);
        self::assertNull($row->duration_seconds);
    }

    /**
     * A real wp_tube_video_actors assignment is picked up on resync — the
     * gap this phase fixed (VideoIndexer previously only preserved
     * whatever actor_ids the index already had, never reading the real
     * relationship table).
     */
    public function test_actor_assignment_is_picked_up_on_resync(): void
    {
        $video_id = $this->create_published_video('Actor Sync Test Video');
        $actor_id = $this->create_actor();

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test seeding against a dedicated custom table with no write API yet (Phase 10).
        $wpdb->insert(
            $wpdb->prefix . 'tube_video_actors',
            [
                'video_id' => $video_id,
                'actor_id' => $actor_id,
            ],
            ['%d', '%d']
        );

        // wp_tube_video_actors alone doesn't fire a resync; re-save the post to trigger one.
        wp_update_post(
            [
                'ID'         => $video_id,
                'post_title' => 'Actor Sync Test Video',
            ]
        );

        $row = Tube_Search_Plugin::instance()->search_index_repository()->find($video_id);

        self::assertNotNull($row);
        self::assertSame([$actor_id], $row->actor_ids);

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
     *
     * @param string $name The term name.
     */
    private function create_category(string $name): int
    {
        $result = wp_insert_term($name . ' ' . uniqid('', true), 'video_category');

        self::assertIsArray($result);

        $term_id                  = (int) $result['term_id'];
        $this->created_term_ids[] = $term_id;

        return $term_id;
    }

    /**
     * Create a real wp_tube_actors row (no write API exists yet — Phase 10 — so this seeds directly).
     */
    private function create_actor(): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $now = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test seeding against a dedicated custom table with no write API yet (Phase 10).
        $wpdb->insert(
            $wpdb->prefix . 'tube_actors',
            [
                'name'       => 'Index Sync Test Actor',
                'slug'       => 'index-sync-test-actor-' . uniqid('', true),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }
}
