<?php
/**
 * Unit tests for ArchiveVideosQuery.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Tests\Unit\Discovery;

use PHPUnit\Framework\TestCase;
use Tube_Search\Discovery\ArchiveVideosQuery;
use Tube_Search\Index\CandidateColumn;
use Tube_Search\Index\SearchIndexRow;
use Tube_Search\Tests\Unit\Discovery\Fixtures\InMemoryDiscoveryRepository;
use Tube_Search\Tests\Unit\Discovery\Fixtures\InMemorySearchCache;

/**
 * Exercises ArchiveVideosQuery's paging/caching logic against fakes —
 * the actual JSON_CONTAINS matching is InMemoryDiscoveryRepository's
 * (and, in production, MySQL's) responsibility, not this class's.
 */
final class ArchiveVideosQueryTest extends TestCase
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
     * @var ArchiveVideosQuery
     */
    private ArchiveVideosQuery $query;

    /**
     * Build a fresh query and fakes for each test.
     */
    protected function setUp(): void
    {
        $this->repository = new InMemoryDiscoveryRepository();
        $this->cache      = new InMemorySearchCache();
        $this->query      = new ArchiveVideosQuery($this->repository, $this->cache);
    }

    /**
     * A matching video is returned, with the correct total count.
     */
    public function test_returns_matching_videos_with_total_count(): void
    {
        $this->repository->seed($this->row(1, [10]));
        $this->repository->seed($this->row(2, [10]));
        $this->repository->seed($this->row(3, [99]));

        $result = $this->query->get(CandidateColumn::CategoryIds, 10);

        self::assertCount(2, $result->items);
        self::assertSame(2, $result->total);
    }

    /**
     * Page/per_page below 1 are clamped up to 1.
     */
    public function test_page_and_per_page_are_clamped_to_a_minimum_of_one(): void
    {
        $result = $this->query->get(CandidateColumn::CategoryIds, 10, 0, 0);

        self::assertSame(1, $result->page);
        self::assertSame(1, $result->per_page);
    }

    /**
     * A second call for the same column/id/page/per_page reuses the cached result.
     */
    public function test_reuses_the_cached_result_on_a_second_call(): void
    {
        $this->repository->seed($this->row(1, [10]));

        $first  = $this->query->get(CandidateColumn::CategoryIds, 10);
        $second = $this->query->get(CandidateColumn::CategoryIds, 10);

        self::assertCount(1, $this->cache->set_calls);
        self::assertEquals($first, $second);
    }

    /**
     * Different candidate columns use different cache keys, even for the same ID.
     */
    public function test_different_columns_use_different_cache_keys(): void
    {
        $this->repository->seed($this->row(1, [10]));

        $this->query->get(CandidateColumn::CategoryIds, 10);
        $this->query->get(CandidateColumn::TagIds, 10);

        self::assertCount(2, $this->cache->set_calls);
        self::assertNotSame($this->cache->set_calls[0]['key'], $this->cache->set_calls[1]['key']);
    }

    /**
     * Build a minimal SearchIndexRow for test seeding.
     *
     * @param int   $video_id     The video post ID.
     * @param int[] $category_ids `video_category` term IDs.
     */
    private function row(int $video_id, array $category_ids): SearchIndexRow
    {
        return new SearchIndexRow($video_id, "Video {$video_id}", null, $category_ids, [], [], [], null, 0, null);
    }
}
