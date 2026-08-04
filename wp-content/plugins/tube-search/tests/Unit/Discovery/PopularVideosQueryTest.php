<?php
/**
 * Unit tests for PopularVideosQuery.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Tests\Unit\Discovery;

use PHPUnit\Framework\TestCase;
use Tube_Search\Discovery\PopularVideosQuery;
use Tube_Search\Index\SearchIndexRow;
use Tube_Search\Tests\Unit\Discovery\Fixtures\InMemoryDiscoveryRepository;
use Tube_Search\Tests\Unit\Discovery\Fixtures\InMemoryPopularityRepository;
use Tube_Search\Tests\Unit\Discovery\Fixtures\InMemorySearchCache;

/**
 * Exercises PopularVideosQuery's re-ordering of an unordered batch lookup
 * back into tube-core's original rank, and its caching, against fakes.
 */
final class PopularVideosQueryTest extends TestCase
{
    /**
     * The fake ranking source the query under test reads from.
     *
     * @var InMemoryPopularityRepository
     */
    private InMemoryPopularityRepository $popularity;

    /**
     * The fake index repository the query under test batch-fetches display fields from.
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
     * @var PopularVideosQuery
     */
    private PopularVideosQuery $query;

    /**
     * Build a fresh query and fakes for each test.
     */
    protected function setUp(): void
    {
        $this->popularity = new InMemoryPopularityRepository();
        $this->repository = new InMemoryDiscoveryRepository();
        $this->cache      = new InMemorySearchCache();
        $this->query      = new PopularVideosQuery($this->popularity, $this->repository, $this->cache);
    }

    /**
     * Trending() re-sorts find_many()'s unordered results back into the ranked order.
     */
    public function test_trending_preserves_rank_order_from_the_popularity_repository(): void
    {
        $this->repository->seed($this->row(1));
        $this->repository->seed($this->row(2));
        $this->repository->seed($this->row(3));

        // Seeded out of index-iteration order on purpose, to prove re-ordering actually happens.
        $this->popularity->top_by_recent_return = [
            [
                'video_id' => 3,
                'count'    => 90,
            ],
            [
                'video_id' => 1,
                'count'    => 50,
            ],
            [
                'video_id' => 2,
                'count'    => 10,
            ],
        ];

        $result = $this->query->trending();

        self::assertSame([3, 1, 2], array_map(static fn (SearchIndexRow $row): int => $row->video_id, $result));
    }

    /**
     * Most_viewed() uses the all-time ranking, not the recent one.
     */
    public function test_most_viewed_uses_the_all_time_ranking(): void
    {
        $this->repository->seed($this->row(1));
        $this->repository->seed($this->row(2));

        $this->popularity->top_by_total_return  = [
            [
                'video_id' => 2,
                'count'    => 500,
            ],
            [
                'video_id' => 1,
                'count'    => 100,
            ],
        ];
        $this->popularity->top_by_recent_return = [
            [
                'video_id' => 1,
                'count'    => 1,
            ],
        ];

        $result = $this->query->most_viewed();

        self::assertSame([2, 1], array_map(static fn (SearchIndexRow $row): int => $row->video_id, $result));
    }

    /**
     * A ranked video ID that no longer exists in the index is silently skipped, not an error.
     */
    public function test_skips_a_ranked_video_id_missing_from_the_index(): void
    {
        $this->repository->seed($this->row(1));

        $this->popularity->top_by_recent_return = [
            [
                'video_id' => 404,
                'count'    => 999,
            ],
            [
                'video_id' => 1,
                'count'    => 5,
            ],
        ];

        $result = $this->query->trending();

        self::assertSame([1], array_map(static fn (SearchIndexRow $row): int => $row->video_id, $result));
    }

    /**
     * Trending() and most_viewed() cache independently — computing one doesn't satisfy the other from cache.
     */
    public function test_trending_and_most_viewed_are_cached_under_separate_keys(): void
    {
        $this->repository->seed($this->row(1));
        $this->popularity->top_by_recent_return = [
            [
                'video_id' => 1,
                'count'    => 1,
            ],
        ];
        $this->popularity->top_by_total_return  = [
            [
                'video_id' => 1,
                'count'    => 1,
            ],
        ];

        $this->query->trending();
        $this->query->most_viewed();

        self::assertCount(2, $this->cache->set_calls);
        self::assertNotSame($this->cache->set_calls[0]['key'], $this->cache->set_calls[1]['key']);
    }

    /**
     * A second call to the same method reuses the cached result instead of recomputing it.
     */
    public function test_reuses_the_cached_result_on_a_second_call(): void
    {
        $this->repository->seed($this->row(1));
        $this->popularity->top_by_recent_return = [
            [
                'video_id' => 1,
                'count'    => 1,
            ],
        ];

        $first  = $this->query->trending();
        $second = $this->query->trending();

        self::assertCount(1, $this->cache->set_calls);
        self::assertEquals($first, $second);
    }

    /**
     * Build a minimal SearchIndexRow for test seeding.
     *
     * @param int $video_id The video post ID.
     */
    private function row(int $video_id): SearchIndexRow
    {
        return new SearchIndexRow($video_id, "Video {$video_id}", null, [], [], [], [], null, 0, null);
    }
}
