<?php
/**
 * Integration tests for tube_search_query() against real MySQL FULLTEXT.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Tests\Integration\Search;

use PHPUnit\Framework\TestCase;
use Tube_Cache\Plugin as Tube_Cache_Plugin;

/**
 * Exercises `tube_search_query()` against a real
 * `MATCH() ... AGAINST() IN NATURAL LANGUAGE MODE` query on
 * `wp_tube_search_index`'s real `FULLTEXT` index — proving the query
 * itself is correct against real MySQL semantics, not only that
 * `SearchQuery`'s own paging/caching logic is correct against a fake.
 */
final class SearchQueryIntegrationTest extends TestCase
{
    /**
     * Video posts created by a test, cleaned up in tearDown() regardless of outcome.
     *
     * @var int[]
     */
    private array $created_video_ids = [];

    /**
     * Delete every video post created by the test.
     */
    protected function tearDown(): void
    {
        foreach ($this->created_video_ids as $video_id) {
            wp_delete_post($video_id, true);
        }

        $this->created_video_ids = [];
    }

    /**
     * A query word present in a video's title returns that video, not an unrelated one.
     */
    public function test_returns_a_video_whose_title_matches_the_query(): void
    {
        $unique = 'Xylophone' . str_replace('.', '', uniqid('', true));

        $matching  = $this->create_published_video("A Video About {$unique} Lessons");
        $unrelated = $this->create_published_video('An Unrelated Video About Cooking');

        $result    = tube_search_query(['q' => $unique]);
        $video_ids = array_map(static fn ($row): int => $row->video_id, $result);

        self::assertContains($matching, $video_ids);
        self::assertNotContains($unrelated, $video_ids);
    }

    /**
     * A blank query returns no results without ever reaching MySQL.
     */
    public function test_blank_query_returns_no_results(): void
    {
        self::assertSame([], tube_search_query(['q' => '   ']));
    }

    /**
     * A query with no matches returns an empty array, not an error.
     */
    public function test_query_with_no_matches_returns_empty(): void
    {
        $no_match = 'Zzznomatchzzz' . str_replace('.', '', uniqid('', true));

        self::assertSame([], tube_search_query(['q' => $no_match]));
    }

    /**
     * A search result page is cached.
     */
    public function test_result_is_cached(): void
    {
        $unique = 'Cacheable' . str_replace('.', '', uniqid('', true));

        $this->create_published_video("A Video About {$unique} Topics");

        tube_search_query(['q' => $unique]);

        $cache_key = 'search:' . md5($unique) . ':1:20';

        self::assertIsArray(Tube_Cache_Plugin::instance()->cache()->get($cache_key));

        Tube_Cache_Plugin::instance()->cache()->delete($cache_key);
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
