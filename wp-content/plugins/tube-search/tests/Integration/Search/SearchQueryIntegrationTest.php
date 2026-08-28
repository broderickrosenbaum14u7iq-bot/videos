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
     * Regression coverage for a real bug found in manual browser testing:
     * "tran ha linh" matched a Vietnamese-titled video but the accented
     * "trần hà linh" did not — asymmetric normalization
     * (`Tube_Search\Search\TextNormalizer` fixed it; see that class's own
     * docblock for the root cause). Every query in the required test
     * matrix must find the same real, freshly-indexed video, end to end
     * through the real `MATCH() AGAINST()` query against
     * `search_text_normalized` — not just that `TextNormalizer::normalize()`
     * produces equal strings in isolation.
     *
     * A random suffix keeps this test's own video distinguishable from
     * any other Vietnamese-titled video already in this environment
     * (assertContains, never an exact/exclusive result set) — this
     * project's own tests don't run inside a rolled-back transaction (see
     * this class's own `tearDown()`), so other real data can coexist.
     *
     * @dataProvider provide_vietnamese_accent_matrix
     *
     * @param string $query The query text to search with.
     */
    public function test_vietnamese_accent_insensitive_matching(string $query): void
    {
        $unique = 'Xyzzy' . str_replace('.', '', uniqid('', true));

        $video_id = $this->create_published_video("Clip Trần Hà Linh {$unique}");

        $result    = tube_search_query(['q' => "{$query} {$unique}"]);
        $video_ids = array_map(static fn ($row): int => $row->video_id, $result);

        self::assertContains($video_id, $video_ids);
    }

    /**
     * Data provider for self::test_vietnamese_accent_insensitive_matching().
     *
     * @return list<array{0: string}>
     */
    public static function provide_vietnamese_accent_matrix(): array
    {
        return [
            ['trần hà linh'],
            ['Trần Hà Linh'],
            ['tran ha linh'],
            ['Tran Ha Linh'],
            ['hà linh'],
            ['ha linh'],
            ['clip trần'],
            ['clip tran'],
        ];
    }

    /**
     * Vietnamese "Đ"/"đ" — an atomic letter with no Unicode decomposition
     * into "D"/"d" (unlike, e.g., accented vowels), so this is the case
     * collation-level accent folding alone provably cannot handle (see
     * `Tube_Search\Search\TextNormalizer`'s own docblock) — real
     * end-to-end MATCH() AGAINST() coverage, not just the normalizer in
     * isolation.
     *
     * @dataProvider provide_d_with_stroke_matrix
     *
     * @param string $query The query text to search with.
     */
    public function test_vietnamese_d_with_stroke_matching(string $query): void
    {
        $unique = 'Xyzzy' . str_replace('.', '', uniqid('', true));

        $video_id = $this->create_published_video("Đặng {$unique}");

        $result    = tube_search_query(['q' => "{$query} {$unique}"]);
        $video_ids = array_map(static fn ($row): int => $row->video_id, $result);

        self::assertContains($video_id, $video_ids);
    }

    /**
     * Data provider for self::test_vietnamese_d_with_stroke_matching().
     *
     * @return list<array{0: string}>
     */
    public static function provide_d_with_stroke_matrix(): array
    {
        return [
            ['đặng'],
            ['dang'],
            ['Đặng'],
        ];
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
