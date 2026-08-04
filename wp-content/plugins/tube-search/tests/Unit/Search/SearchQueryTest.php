<?php
/**
 * Unit tests for SearchQuery.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Tube_Search\Index\SearchIndexRow;
use Tube_Search\Search\SearchQuery;
use Tube_Search\Tests\Unit\Discovery\Fixtures\InMemoryDiscoveryRepository;
use Tube_Search\Tests\Unit\Discovery\Fixtures\InMemorySearchCache;

/**
 * Exercises SearchQuery's paging normalization, blank-query
 * short-circuiting, and caching against fakes — the actual `FULLTEXT`
 * matching is `InMemoryDiscoveryRepository`'s (and, in production,
 * MySQL's) responsibility, not this class's.
 */
final class SearchQueryTest extends TestCase
{
    /**
     * The fake index repository the query under test reads from.
     *
     * @var InMemoryDiscoveryRepository
     */
    private InMemoryDiscoveryRepository $repository;

    /**
     * The fake cache the query under test reads/writes through.
     *
     * @var InMemorySearchCache
     */
    private InMemorySearchCache $cache;

    /**
     * The query under test.
     *
     * @var SearchQuery
     */
    private SearchQuery $query;

    /**
     * Build a fresh query and fakes for each test.
     */
    protected function setUp(): void
    {
        $this->repository = new InMemoryDiscoveryRepository();
        $this->cache      = new InMemorySearchCache();
        $this->query      = new SearchQuery($this->repository, $this->cache);
    }

    /**
     * A blank query (after trimming) short-circuits to an empty result without touching the repository or cache.
     */
    public function test_blank_query_returns_empty_without_querying(): void
    {
        $this->repository->seed($this->row(1, 'Anything'));

        self::assertSame([], $this->query->search(''));
        self::assertSame([], $this->query->search('   '));
        self::assertSame([], $this->cache->set_calls);
    }

    /**
     * A matching query returns results and caches them.
     */
    public function test_matching_query_returns_results_and_caches_them(): void
    {
        $this->repository->seed($this->row(1, 'A Great Video'));
        $this->repository->seed($this->row(2, 'Unrelated'));

        $result = $this->query->search('great');

        self::assertSame([1], array_map(static fn (SearchIndexRow $row): int => $row->video_id, $result));
        self::assertCount(1, $this->cache->set_calls);
    }

    /**
     * Page/per_page below 1 are clamped up to 1, not passed through negative or zero.
     */
    public function test_page_and_per_page_are_clamped_to_a_minimum_of_one(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->repository->seed($this->row($i, 'Match'));
        }

        $result = $this->query->search('match', 0, 0);

        // per_page clamped to 1 -> only the first result on the (clamped) first page.
        self::assertCount(1, $result);
    }

    /**
     * Different query text produces different cache keys.
     */
    public function test_different_query_text_uses_different_cache_keys(): void
    {
        $this->repository->seed($this->row(1, 'Alpha'));
        $this->repository->seed($this->row(2, 'Beta'));

        $this->query->search('alpha');
        $this->query->search('beta');

        self::assertCount(2, $this->cache->set_calls);
        self::assertNotSame($this->cache->set_calls[0]['key'], $this->cache->set_calls[1]['key']);
    }

    /**
     * The same query, page, and per_page reuses the cached result instead of recomputing it.
     */
    public function test_reuses_the_cached_result_for_the_same_query_and_page(): void
    {
        $this->repository->seed($this->row(1, 'Alpha'));

        $first  = $this->query->search('alpha', 1, 10);
        $second = $this->query->search('alpha', 1, 10);

        self::assertCount(1, $this->cache->set_calls);
        self::assertEquals($first, $second);
    }

    /**
     * Build a minimal SearchIndexRow for test seeding.
     *
     * @param int    $video_id The video post ID.
     * @param string $title    The video's title.
     */
    private function row(int $video_id, string $title): SearchIndexRow
    {
        return new SearchIndexRow($video_id, $title, null, [], [], [], [], null, 0, null);
    }
}
