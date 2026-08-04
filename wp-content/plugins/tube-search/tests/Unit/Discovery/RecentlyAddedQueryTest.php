<?php
/**
 * Unit tests for RecentlyAddedQuery.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Tests\Unit\Discovery;

use PHPUnit\Framework\TestCase;
use Tube_Search\Discovery\RecentlyAddedQuery;
use Tube_Search\Index\SearchIndexRow;
use Tube_Search\Tests\Unit\Discovery\Fixtures\InMemoryDiscoveryRepository;
use Tube_Search\Tests\Unit\Discovery\Fixtures\InMemorySearchCache;

/**
 * Exercises RecentlyAddedQuery's cache-first wrapper against fakes — the
 * actual `published_at DESC` ordering is `InMemoryDiscoveryRepository`'s
 * (and, in production, MySQL's) responsibility, not this class's.
 */
final class RecentlyAddedQueryTest extends TestCase
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
     * @var RecentlyAddedQuery
     */
    private RecentlyAddedQuery $query;

    /**
     * Build a fresh query and fakes for each test.
     */
    protected function setUp(): void
    {
        $this->repository = new InMemoryDiscoveryRepository();
        $this->cache      = new InMemorySearchCache();
        $this->query      = new RecentlyAddedQuery($this->repository, $this->cache);
    }

    /**
     * A first call delegates to the repository and caches what it returns.
     */
    public function test_first_call_delegates_to_the_repository_and_caches_the_result(): void
    {
        $this->repository->seed($this->row(1, '2026-01-01 00:00:00'));
        $this->repository->seed($this->row(2, '2026-02-01 00:00:00'));

        $result = $this->query->get(2);

        self::assertSame([2, 1], array_map(static fn (SearchIndexRow $row): int => $row->video_id, $result));
        self::assertCount(1, $this->cache->set_calls);
        self::assertSame('recently_added', $this->cache->set_calls[0]['key']);
    }

    /**
     * A second call reuses the cached result instead of hitting the repository again.
     */
    public function test_reuses_the_cached_result_on_a_second_call(): void
    {
        $this->repository->seed($this->row(1, '2026-01-01 00:00:00'));

        $first  = $this->query->get();
        $second = $this->query->get();

        self::assertCount(1, $this->cache->set_calls);
        self::assertEquals($first, $second);
    }

    /**
     * Build a minimal SearchIndexRow for test seeding.
     *
     * @param int    $video_id     The video post ID.
     * @param string $published_at MySQL DATETIME string.
     */
    private function row(int $video_id, string $published_at): SearchIndexRow
    {
        return new SearchIndexRow($video_id, "Video {$video_id}", null, [], [], [], [], null, 0, $published_at);
    }
}
