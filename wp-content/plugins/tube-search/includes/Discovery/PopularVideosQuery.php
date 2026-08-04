<?php
/**
 * "Trending" and "Most Viewed" — ranked by tube-core's precomputed statistics.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Discovery;

use Tube_Search\Cache\SearchCacheInterface;
use Tube_Search\Index\DiscoveryRepositoryInterface;
use Tube_Search\Index\SearchIndexRow;

/**
 * "Trending" and "Most Viewed", per ARCHITECTURE.md §12 Phase 7: both
 * read their ranking from tube-core's precomputed statistics table
 * (`PopularityRepositoryInterface`, never a runtime aggregation), then
 * batch-fetch display fields for that exact video ID list from
 * tube-search's own index (`DiscoveryRepositoryInterface::find_many()`)
 * — two queries total, regardless of how many videos are returned (no
 * N+1). `find_many()` doesn't preserve order, so this class re-sorts the
 * batch back into tube-core's original ranking.
 *
 * One class, not two nearly-identical ones: "Trending" and "Most Viewed"
 * differ only in which `PopularityRepositoryInterface` method and cache
 * key they use — everything else (batch lookup, re-ordering, caching) is
 * identical, so sharing it here is the real reduction in duplication,
 * not a premature abstraction.
 *
 * Pure orchestration logic — no WordPress, fully unit-tested against fakes.
 */
final class PopularVideosQuery
{
    /**
     * How many videos to return when the caller doesn't ask for a specific number.
     */
    private const DEFAULT_LIMIT = 12;

    /**
     * How long a computed listing stays cached — `Tube_Cache\Events\
     * CachePurgeSubscriber` purges both keys explicitly on every stats
     * rollup (ARCHITECTURE.md §16.1), so this TTL is only the backstop
     * for the (harmless) case a purge is somehow missed.
     */
    private const CACHE_TTL_SECONDS = 600;

    /**
     * Must match `Tube_Cache\Cache\CacheKeys::trending()` exactly — see
     * `RelatedVideosFinder`'s docblock for why tube-search can't
     * reference that method directly.
     */
    private const TRENDING_CACHE_KEY = 'trending';

    /**
     * Must match `Tube_Cache\Cache\CacheKeys::most_viewed()` exactly.
     */
    private const MOST_VIEWED_CACHE_KEY = 'most_viewed';

    /**
     * Construct around the collaborators this query reads and caches through.
     *
     * @param PopularityRepositoryInterface $popularity Ranks video IDs by view count.
     * @param DiscoveryRepositoryInterface  $repository Resolves display fields for a ranked ID list.
     * @param SearchCacheInterface          $cache      Cached through.
     */
    public function __construct(
        private readonly PopularityRepositoryInterface $popularity,
        private readonly DiscoveryRepositoryInterface $repository,
        private readonly SearchCacheInterface $cache
    ) {
    }

    /**
     * The videos with the highest recent (7-day) view count.
     *
     * @param int $limit Maximum number of videos to return.
     *
     * @return list<SearchIndexRow>
     */
    public function trending(int $limit = self::DEFAULT_LIMIT): array
    {
        return $this->cached(
            self::TRENDING_CACHE_KEY,
            fn (): array => $this->resolve($this->popularity->top_by_recent($limit))
        );
    }

    /**
     * The videos with the highest all-time view count.
     *
     * @param int $limit Maximum number of videos to return.
     *
     * @return list<SearchIndexRow>
     */
    public function most_viewed(int $limit = self::DEFAULT_LIMIT): array
    {
        return $this->cached(
            self::MOST_VIEWED_CACHE_KEY,
            fn (): array => $this->resolve($this->popularity->top_by_total($limit))
        );
    }

    /**
     * Cache-first wrapper shared by both public methods.
     *
     * @param string                           $cache_key Which fixed cache key to use.
     * @param callable(): list<SearchIndexRow> $compute What to run on a cache miss.
     *
     * @return list<SearchIndexRow>
     */
    private function cached(string $cache_key, callable $compute): array
    {
        $cached = $this->cache->get($cache_key);

        if (is_array($cached)) {
            // The cache round-trips exactly what $compute() itself returns.
            /** @var list<SearchIndexRow> $cached */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
            return $cached;
        }

        $result = $compute();

        $this->cache->set($cache_key, $result, self::CACHE_TTL_SECONDS);

        return $result;
    }

    /**
     * Batch-resolve a ranked video-ID list into display rows, preserving
     * the original rank order.
     *
     * @param list<array{video_id: int, count: int}> $ranked Video ID + count, highest first.
     *
     * @return list<SearchIndexRow>
     */
    private function resolve(array $ranked): array
    {
        if ([] === $ranked) {
            return [];
        }

        $video_ids  = array_map(static fn (array $entry): int => $entry['video_id'], $ranked);
        $rows_by_id = [];

        foreach ($this->repository->find_many($video_ids) as $row) {
            $rows_by_id[ $row->video_id ] = $row;
        }

        $result = [];

        foreach ($video_ids as $video_id) {
            if (isset($rows_by_id[ $video_id ])) {
                $result[] = $rows_by_id[ $video_id ];
            }
        }

        return $result;
    }
}
