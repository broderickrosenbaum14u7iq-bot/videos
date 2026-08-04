<?php
/**
 * Integration tests for tube_search_related_videos() against real data.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Tests\Integration\Discovery;

use PHPUnit\Framework\TestCase;
use Tube_Cache\Plugin as Tube_Cache_Plugin;
use Tube_Search\Index\SearchIndexRow;
use Tube_Search\Plugin as Tube_Search_Plugin;

/**
 * Exercises `tube_search_related_videos()` end-to-end: real published
 * videos, a real `video_category` term shared between two of them, real
 * FULLTEXT-adjacent index rows, and the real Redis-backed cache
 * (`Tube_Cache\Plugin::instance()->cache()`) tube-search's discovery
 * layer reads/writes through. Actor/studio candidates are seeded via
 * `search_index_repository()->upsert()` directly (a legitimate public
 * accessor) since no real actor/studio assignment UI exists yet
 * (tube-admin, Phase 10) — see `VideoIndexer`'s own docblock.
 */
final class RelatedVideosIntegrationTest extends TestCase
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
     * Delete every video post and term created by the test.
     */
    protected function tearDown(): void
    {
        foreach ($this->created_video_ids as $video_id) {
            Tube_Cache_Plugin::instance()->cache()->delete('related_videos:' . $video_id);
            wp_delete_post($video_id, true);
        }

        foreach ($this->created_term_ids as $term_id) {
            wp_delete_term($term_id, 'video_category');
        }

        $this->created_video_ids = [];
        $this->created_term_ids  = [];
    }

    /**
     * A video sharing a real category with the source video is returned; an
     * unrelated video is not — limit is capped to the exact number of real
     * category matches, so the random fallback (which would otherwise
     * legitimately fill remaining slots with $unrelated) never engages.
     */
    public function test_returns_a_video_sharing_a_real_category(): void
    {
        $term_id = $this->create_category('Related Integration Category');

        $source    = $this->create_published_video('Source Video');
        $related   = $this->create_published_video('Related Video');
        $unrelated = $this->create_published_video('Unrelated Video');

        $this->assign_category_and_resync($source, $term_id);
        $this->assign_category_and_resync($related, $term_id);

        $result    = tube_search_related_videos($source, 1);
        $video_ids = array_map(static fn (SearchIndexRow $row): int => $row->video_id, $result);

        self::assertContains($related, $video_ids);
        self::assertNotContains($unrelated, $video_ids);
        self::assertNotContains($source, $video_ids);
    }

    /**
     * A video sharing an actor outranks a video sharing only a studio, matching the documented priority cascade.
     */
    public function test_actor_match_outranks_studio_only_match(): void
    {
        $source      = $this->create_published_video('Cascade Source Video');
        $actor_match = $this->create_published_video('Actor Match Video');
        $studio_only = $this->create_published_video('Studio Only Video');

        $repository = Tube_Search_Plugin::instance()->search_index_repository();

        $repository->upsert($source, 'Cascade Source Video', null, [], [], [77], [88], null, 0, gmdate('Y-m-d H:i:s'));
        $repository->upsert(
            $actor_match,
            'Actor Match Video',
            null,
            [],
            [],
            [77],
            [],
            null,
            0,
            gmdate('Y-m-d H:i:s')
        );
        $repository->upsert(
            $studio_only,
            'Studio Only Video',
            null,
            [],
            [],
            [],
            [88],
            null,
            0,
            gmdate('Y-m-d H:i:s')
        );

        $result = tube_search_related_videos($source, 1);

        self::assertCount(1, $result);
        self::assertSame($actor_match, $result[0]->video_id);
    }

    /**
     * A second call for the same video is served from the real Redis-backed cache.
     */
    public function test_result_is_cached(): void
    {
        $term_id = $this->create_category('Cache Integration Category');
        $source  = $this->create_published_video('Cache Source Video');
        $related = $this->create_published_video('Cache Related Video');

        $this->assign_category_and_resync($source, $term_id);
        $this->assign_category_and_resync($related, $term_id);

        tube_search_related_videos($source, 5);

        $cached = Tube_Cache_Plugin::instance()->cache()->get('related_videos:' . $source);

        self::assertIsArray($cached);
        self::assertNotSame([], $cached);
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
