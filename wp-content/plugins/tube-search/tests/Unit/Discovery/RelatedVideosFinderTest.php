<?php
/**
 * Unit tests for RelatedVideosFinder.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Tests\Unit\Discovery;

use PHPUnit\Framework\TestCase;
use Tube_Search\Discovery\RelatedVideosFinder;
use Tube_Search\Index\SearchIndexRow;
use Tube_Search\Tests\Unit\Discovery\Fixtures\InMemoryDiscoveryRepository;
use Tube_Search\Tests\Unit\Discovery\Fixtures\InMemorySearchCache;

/**
 * Exercises RelatedVideosFinder's priority cascade (same categories,
 * same actors, same studio, similar tags, random fallback) against a
 * genuinely-functional fake repository — no database, no WordPress.
 */
final class RelatedVideosFinderTest extends TestCase
{
    /**
     * The fake repository the finder under test reads from.
     *
     * @var InMemoryDiscoveryRepository
     */
    private InMemoryDiscoveryRepository $repository;

    /**
     * The fake cache the finder under test reads/writes through.
     *
     * @var InMemorySearchCache
     */
    private InMemorySearchCache $cache;

    /**
     * The finder under test.
     *
     * @var RelatedVideosFinder
     */
    private RelatedVideosFinder $finder;

    /**
     * Build a fresh finder and fakes for each test.
     */
    protected function setUp(): void
    {
        $this->repository = new InMemoryDiscoveryRepository();
        $this->cache      = new InMemorySearchCache();
        $this->finder     = new RelatedVideosFinder($this->repository, $this->cache);
    }

    /**
     * A candidate sharing a category outranks one sharing only an actor.
     */
    public function test_same_category_takes_priority_over_same_actor(): void
    {
        $this->repository->seed($this->row(1, category_ids: [10], actor_ids: [20]));
        $this->repository->seed($this->row(2, category_ids: [10])); // Shares category only.
        $this->repository->seed($this->row(3, actor_ids: [20])); // Shares actor only.

        $result = $this->finder->find(1, 1);

        self::assertCount(1, $result);
        self::assertSame(2, $result[0]->video_id);
    }

    /**
     * Later cascade steps fill whatever slots earlier steps left empty.
     */
    public function test_falls_through_the_cascade_to_fill_remaining_slots(): void
    {
        $this->repository->seed($this->row(1, category_ids: [10], actor_ids: [20]));
        $this->repository->seed($this->row(2, category_ids: [10]));
        $this->repository->seed($this->row(3, actor_ids: [20]));

        $result = $this->finder->find(1, 2);

        self::assertCount(2, $result);
        self::assertSame([2, 3], array_map(static fn (SearchIndexRow $row): int => $row->video_id, $result));
    }

    /**
     * The source video is never returned, even though it trivially "shares" its own attributes.
     */
    public function test_never_returns_the_source_video_itself(): void
    {
        $this->repository->seed($this->row(1, category_ids: [10]));

        $result = $this->finder->find(1, 5);

        self::assertSame([], $result);
    }

    /**
     * A category candidate already collected is not collected again by a later step it also matches.
     */
    public function test_does_not_duplicate_a_video_across_cascade_steps(): void
    {
        $this->repository->seed($this->row(1, category_ids: [10], actor_ids: [20]));
        $this->repository->seed($this->row(2, category_ids: [10], actor_ids: [20])); // Matches both category and actor.

        $result = $this->finder->find(1, 5);

        self::assertCount(1, $result);
        self::assertSame(2, $result[0]->video_id);
    }

    /**
     * With nothing in common with any other video, the result is filled by the random fallback.
     */
    public function test_falls_back_to_random_when_nothing_shares_any_signal(): void
    {
        $this->repository->seed($this->row(1, category_ids: [10]));
        $this->repository->seed($this->row(2, category_ids: [99])); // No overlap with video 1 at all.

        $result = $this->finder->find(1, 5);

        self::assertCount(1, $result);
        self::assertSame(2, $result[0]->video_id);
    }

    /**
     * An unindexed source video still gets a result, via the random fallback.
     */
    public function test_unindexed_source_video_falls_back_to_random(): void
    {
        $this->repository->seed($this->row(2, category_ids: [10]));

        $result = $this->finder->find(999, 5);

        self::assertCount(1, $result);
        self::assertSame(2, $result[0]->video_id);
    }

    /**
     * A second call for the same video reuses the cached result instead of recomputing it.
     */
    public function test_reuses_the_cached_result_on_a_second_call(): void
    {
        $this->repository->seed($this->row(1, category_ids: [10]));
        $this->repository->seed($this->row(2, category_ids: [10]));

        $this->finder->find(1, 5);
        $this->finder->find(1, 5);

        self::assertSame(1, $this->repository->find_calls);
        self::assertCount(1, $this->cache->set_calls);
    }

    /**
     * Build a SearchIndexRow for test seeding, with sensible defaults for fields the test doesn't care about.
     *
     * @param int   $video_id     The video post ID.
     * @param int[] $category_ids `video_category` term IDs.
     * @param int[] $actor_ids    Actor IDs.
     * @param int[] $studio_ids   Studio IDs.
     * @param int[] $tag_ids      `video_tag` term IDs.
     */
    private function row(
        int $video_id,
        array $category_ids = [],
        array $actor_ids = [],
        array $studio_ids = [],
        array $tag_ids = []
    ): SearchIndexRow {
        return new SearchIndexRow(
            $video_id,
            "Video {$video_id}",
            null,
            $category_ids,
            $tag_ids,
            $actor_ids,
            $studio_ids,
            null,
            0,
            null
        );
    }
}
