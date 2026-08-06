<?php
/**
 * Integration tests for ActorRepository/StudioRepository's write API.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Integration\Content;

use PHPUnit\Framework\TestCase;
use Tube_Core\Plugin as Tube_Core_Plugin;

/**
 * Exercises `ActorRepository`/`StudioRepository::replace_for_video()`/
 * `bulk_add()`/`bulk_remove()` (Phase 10) against the real
 * `wp_tube_video_actors`/`wp_tube_video_studios` tables — the write API
 * `ActorStudioIntegrationTest`'s own docblock said didn't exist yet.
 * Split into its own file (rather than added to that one) the same way
 * `SitemapGeneratorIntegrationTest`/`SitemapRoutingIntegrationTest` were
 * split: a materially different concern (writes vs. reads/routing), not a
 * missed opportunity to share a test class.
 */
final class ActorStudioWriteApiIntegrationTest extends TestCase
{
    /**
     * Actor IDs created by a test, cleaned up in tearDown().
     *
     * @var int[]
     */
    private array $created_actor_ids = [];

    /**
     * Studio IDs created by a test, cleaned up in tearDown().
     *
     * @var int[]
     */
    private array $created_studio_ids = [];

    /**
     * Video posts created by a test, cleaned up in tearDown().
     *
     * @var int[]
     */
    private array $created_video_ids = [];

    /**
     * Delete every row created by the test.
     */
    protected function tearDown(): void
    {
        foreach ($this->created_actor_ids as $actor_id) {
            global $wpdb;
            /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup against dedicated custom tables.
            $wpdb->delete($wpdb->prefix . 'tube_video_actors', ['actor_id' => $actor_id], ['%d']);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup against dedicated custom tables.
            $wpdb->delete($wpdb->prefix . 'tube_actors', ['id' => $actor_id], ['%d']);
        }

        foreach ($this->created_studio_ids as $studio_id) {
            global $wpdb;
            /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup against dedicated custom tables.
            $wpdb->delete($wpdb->prefix . 'tube_video_studios', ['studio_id' => $studio_id], ['%d']);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup against dedicated custom tables.
            $wpdb->delete($wpdb->prefix . 'tube_studios', ['id' => $studio_id], ['%d']);
        }

        foreach ($this->created_video_ids as $video_id) {
            wp_delete_post($video_id, true);
        }

        $this->created_actor_ids  = [];
        $this->created_studio_ids = [];
        $this->created_video_ids  = [];
    }

    /**
     * Replace_for_video() assigns actors from an empty starting state.
     */
    public function test_replace_for_video_assigns_actors_from_empty(): void
    {
        $repository = Tube_Core_Plugin::instance()->actor_repository();
        $video_id   = $this->create_video();
        $actor_a    = $this->create_actor('Replace A');
        $actor_b    = $this->create_actor('Replace B');

        $repository->replace_for_video($video_id, [$actor_a, $actor_b]);

        $ids = $repository->actor_ids_for_video($video_id);
        sort($ids);
        self::assertSame([$actor_a, $actor_b], $ids);
        self::assertSame(1, $repository->count_videos_for_actor($actor_a));
        self::assertSame(1, $repository->count_videos_for_actor($actor_b));
    }

    /**
     * A second replace_for_video() call correctly diffs against the
     * current assignment — adds a new actor, removes a dropped one, and
     * leaves an unchanged one alone.
     */
    public function test_replace_for_video_diffs_against_current_assignment(): void
    {
        $repository = Tube_Core_Plugin::instance()->actor_repository();
        $video_id   = $this->create_video();
        $keep       = $this->create_actor('Keep');
        $drop       = $this->create_actor('Drop');
        $added      = $this->create_actor('Added');

        $repository->replace_for_video($video_id, [$keep, $drop]);
        $repository->replace_for_video($video_id, [$keep, $added]);

        $ids      = $repository->actor_ids_for_video($video_id);
        $expected = [$keep, $added];
        sort($ids);
        sort($expected);
        self::assertSame($expected, $ids);
        self::assertSame(1, $repository->count_videos_for_actor($keep));
        self::assertSame(0, $repository->count_videos_for_actor($drop));
        self::assertSame(1, $repository->count_videos_for_actor($added));
    }

    /**
     * Replace_for_video() with an empty list clears every assignment.
     */
    public function test_replace_for_video_with_empty_list_clears_assignments(): void
    {
        $repository = Tube_Core_Plugin::instance()->actor_repository();
        $video_id   = $this->create_video();
        $actor_id   = $this->create_actor('Cleared');

        $repository->replace_for_video($video_id, [$actor_id]);
        $repository->replace_for_video($video_id, []);

        self::assertSame([], $repository->actor_ids_for_video($video_id));
        self::assertSame(0, $repository->count_videos_for_actor($actor_id));
    }

    /**
     * Bulk_add() adds one actor across several videos without disturbing
     * an already-assigned actor on one of them, and refreshes video_count
     * to the real total.
     */
    public function test_bulk_add_actor_across_several_videos(): void
    {
        $repository = Tube_Core_Plugin::instance()->actor_repository();
        $video_1    = $this->create_video();
        $video_2    = $this->create_video();
        $existing   = $this->create_actor('Already There');
        $bulk       = $this->create_actor('Bulk Added');

        $repository->replace_for_video($video_1, [$existing]);

        $inserted = $repository->bulk_add([$video_1, $video_2], [$bulk]);

        self::assertSame(2, $inserted);

        $video_1_ids      = $repository->actor_ids_for_video($video_1);
        $expected_video_1 = [$existing, $bulk];
        sort($video_1_ids);
        sort($expected_video_1);
        self::assertSame($expected_video_1, $video_1_ids);
        self::assertSame([$bulk], $repository->actor_ids_for_video($video_2));
        self::assertSame(2, $repository->count_videos_for_actor($bulk));
        self::assertSame(1, $repository->count_videos_for_actor($existing));
    }

    /**
     * Bulk_add() is idempotent: re-adding an already-assigned pair
     * inserts nothing new (INSERT IGNORE), not a duplicate row or an error.
     */
    public function test_bulk_add_is_idempotent_for_an_existing_pair(): void
    {
        $repository = Tube_Core_Plugin::instance()->actor_repository();
        $video_id   = $this->create_video();
        $actor_id   = $this->create_actor('Idempotent');

        $first  = $repository->bulk_add([$video_id], [$actor_id]);
        $second = $repository->bulk_add([$video_id], [$actor_id]);

        self::assertSame(1, $first);
        self::assertSame(0, $second);
        self::assertSame(1, $repository->count_videos_for_actor($actor_id));
    }

    /**
     * Bulk_remove() removes exactly the requested pairs and refreshes
     * video_count, leaving an unrelated assignment untouched.
     */
    public function test_bulk_remove_removes_only_the_requested_pairs(): void
    {
        $repository = Tube_Core_Plugin::instance()->actor_repository();
        $video_1    = $this->create_video();
        $video_2    = $this->create_video();
        $actor_id   = $this->create_actor('Remove Me');

        $repository->bulk_add([$video_1, $video_2], [$actor_id]);

        $deleted = $repository->bulk_remove([$video_1], [$actor_id]);

        self::assertSame(1, $deleted);
        self::assertSame([], $repository->actor_ids_for_video($video_1));
        self::assertSame([$actor_id], $repository->actor_ids_for_video($video_2));
        self::assertSame(1, $repository->count_videos_for_actor($actor_id));
    }

    /**
     * StudioRepository's write API has the same behavior as
     * ActorRepository's — one representative test, not a full duplicate
     * suite, since both implementations share the same shape and this
     * test would otherwise just be a mechanical copy of
     * test_replace_for_video_diffs_against_current_assignment().
     */
    public function test_studio_replace_for_video_diffs_against_current_assignment(): void
    {
        $repository = Tube_Core_Plugin::instance()->studio_repository();
        $video_id   = $this->create_video();
        $keep       = $this->create_studio('Keep Studio');
        $drop       = $this->create_studio('Drop Studio');
        $added      = $this->create_studio('Added Studio');

        $repository->replace_for_video($video_id, [$keep, $drop]);
        $repository->replace_for_video($video_id, [$keep, $added]);

        $ids      = $repository->studio_ids_for_video($video_id);
        $expected = [$keep, $added];
        sort($ids);
        sort($expected);
        self::assertSame($expected, $ids);
        self::assertSame(1, $repository->count_videos_for_studio($keep));
        self::assertSame(0, $repository->count_videos_for_studio($drop));
        self::assertSame(1, $repository->count_videos_for_studio($added));
    }

    /**
     * Create a real actor row, tracked for teardown.
     *
     * @param string $name The actor's name.
     */
    private function create_actor(string $name): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $now = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test seeding against a dedicated custom table; this repository's own read methods (find/find_by_slug) are what's exercised elsewhere, not this row's creation path.
        $wpdb->insert(
            $wpdb->prefix . 'tube_actors',
            [
                'name'       => $name,
                'slug'       => 'placeholder',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s']
        );

        $actor_id = (int) $wpdb->insert_id;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see comment above.
        $wpdb->update(
            $wpdb->prefix . 'tube_actors',
            ['slug' => sanitize_title($name) . '-' . $actor_id],
            ['id' => $actor_id],
            ['%s'],
            ['%d']
        );

        $this->created_actor_ids[] = $actor_id;

        return $actor_id;
    }

    /**
     * Create a real studio row, tracked for teardown.
     *
     * @param string $name The studio's name.
     */
    private function create_studio(string $name): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $now = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test seeding against a dedicated custom table.
        $wpdb->insert(
            $wpdb->prefix . 'tube_studios',
            [
                'name'       => $name,
                'slug'       => 'placeholder',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s']
        );

        $studio_id = (int) $wpdb->insert_id;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see comment above.
        $wpdb->update(
            $wpdb->prefix . 'tube_studios',
            ['slug' => sanitize_title($name) . '-' . $studio_id],
            ['id' => $studio_id],
            ['%s'],
            ['%d']
        );

        $this->created_studio_ids[] = $studio_id;

        return $studio_id;
    }

    /**
     * Create a real published video post, tracked for teardown.
     */
    private function create_video(): int
    {
        $video_id = wp_insert_post(
            [
                'post_type'   => 'video',
                'post_title'  => 'Actor/Studio Write API Test Video',
                'post_status' => 'publish',
            ],
            true
        );

        self::assertIsInt($video_id);
        $this->created_video_ids[] = $video_id;

        return $video_id;
    }
}
