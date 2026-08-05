<?php
/**
 * Finds related videos for one video, in priority order.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Discovery;

use Tube_Search\Cache\SearchCacheInterface;
use Tube_Search\Index\CandidateColumn;
use Tube_Search\Index\DiscoveryRepositoryInterface;
use Tube_Search\Index\SearchIndexRow;

/**
 * Finds related videos for one video, per ARCHITECTURE.md §12 Phase 7's
 * explicit priority cascade: same categories, then same actors, then
 * same studio, then similar tags, then a random fallback — each step
 * filling only the slots the previous steps left empty, never
 * duplicating a video across steps, and never returning the source
 * video itself.
 *
 * Pure orchestration logic against `DiscoveryRepositoryInterface` and
 * `SearchCacheInterface` — no WordPress, fully unit-tested against fakes.
 *
 * Cached per video ID with a 15-minute TTL and no reactive purge beyond
 * the source video's *own* cache entry (purged by
 * `Tube_Cache\Events\CachePurgeSubscriber::purge_video()` on that
 * video's own publish/update/delete). A *different* video newly sharing
 * this one's category (say) can make this list stale until the TTL
 * expires — an accepted, bounded tradeoff, the same class of decision
 * ARCHITECTURE.md §16.2 already makes for deep archive pages, rather
 * than an unbounded fan-out purge across every potentially-affected
 * video. See `PHASE-7.md` for the full reasoning.
 */
final class RelatedVideosFinder
{
    /**
     * How many related videos to return when the caller doesn't ask for a specific number.
     */
    private const DEFAULT_LIMIT = 12;

    /**
     * How long a computed related-videos list stays cached.
     */
    private const CACHE_TTL_SECONDS = 900;

    /**
     * Must match `Tube_Cache\Cache\CacheKeys::related_videos()` exactly
     * — see this class's own docblock for why tube-search can't
     * reference that method directly.
     *
     * @param int $video_id The video to build the cache key for.
     */
    private static function cache_key(int $video_id): string
    {
        return 'related_videos:' . $video_id;
    }

    /**
     * Construct around the collaborators this finder queries and caches through.
     *
     * @param DiscoveryRepositoryInterface $repository Read from.
     * @param SearchCacheInterface         $cache      Cached through.
     */
    public function __construct(
        private readonly DiscoveryRepositoryInterface $repository,
        private readonly SearchCacheInterface $cache
    ) {
    }

    /**
     * Find related videos for one video.
     *
     * @param int $video_id The video to find related videos for.
     * @param int $limit    Maximum number of videos to return.
     *
     * @return list<SearchIndexRow>
     */
    public function find(int $video_id, int $limit = self::DEFAULT_LIMIT): array
    {
        $cache_key = self::cache_key($video_id);
        $cached    = $this->cache->get($cache_key);

        if (is_array($cached)) {
            // Cached as plain arrays (see SearchIndexRow::to_array()'s docblock for why), decoded back here.
            /** @var list<array<string, mixed>> $cached */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
            return array_map(SearchIndexRow::from_array(...), $cached);
        }

        $result = $this->compute($video_id, $limit);

        $cacheable = array_map(static fn (SearchIndexRow $row): array => $row->to_array(), $result);
        $this->cache->set($cache_key, $cacheable, self::CACHE_TTL_SECONDS);

        return $result;
    }

    /**
     * Run the actual priority cascade.
     *
     * @param int $video_id The video to find related videos for.
     * @param int $limit    Maximum number of videos to return.
     *
     * @return list<SearchIndexRow>
     */
    private function compute(int $video_id, int $limit): array
    {
        $source = $this->repository->find($video_id);

        if (null === $source) {
            return $this->repository->find_random([$video_id], $limit);
        }

        $excluded = [$video_id];
        $result   = [];

        $this->collect($result, $excluded, CandidateColumn::CategoryIds, $source->category_ids, $limit);
        $this->collect($result, $excluded, CandidateColumn::ActorIds, $source->actor_ids, $limit);
        $this->collect($result, $excluded, CandidateColumn::StudioIds, $source->studio_ids, $limit);
        $this->collect($result, $excluded, CandidateColumn::TagIds, $source->tag_ids, $limit);

        if (count($result) < $limit) {
            $fallback = $this->repository->find_random($excluded, $limit - count($result));

            foreach ($fallback as $row) {
                $result[] = $row;
            }
        }

        return array_values($result);
    }

    /**
     * Run one cascade step, appending its matches to $result and $excluded in place.
     *
     * @param SearchIndexRow[] $result        The accumulated result so far (by reference).
     * @param int[]            $excluded      The video IDs already used, plus the source video (by reference).
     * @param CandidateColumn  $column        Which JSON-array column this step matches against.
     * @param int[]            $candidate_ids The source video's own IDs for that column.
     * @param int              $limit         The overall target result size.
     */
    private function collect(
        array &$result,
        array &$excluded,
        CandidateColumn $column,
        array $candidate_ids,
        int $limit
    ): void {
        if ([] === $candidate_ids || count($result) >= $limit) {
            return;
        }

        $found = $this->repository->find_by_ids($column, $candidate_ids, $excluded, $limit - count($result));

        foreach ($found as $row) {
            $result[]   = $row;
            $excluded[] = $row->video_id;
        }
    }
}
