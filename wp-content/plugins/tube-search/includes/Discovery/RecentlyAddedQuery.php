<?php
/**
 * "Recently Added" — indexed publish-date ordering.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Discovery;

use Tube_Search\Cache\SearchCacheInterface;
use Tube_Search\Index\DiscoveryRepositoryInterface;
use Tube_Search\Index\SearchIndexRow;

/**
 * "Recently Added", per ARCHITECTURE.md §12 Phase 7: an indexed
 * `published_at DESC` read (`DiscoveryRepositoryInterface::recently_added()`),
 * cached with an explicit purge on every new publish
 * (`Tube_Cache\Events\CachePurgeSubscriber::handle_video_published()`).
 *
 * Pure orchestration logic — no WordPress, fully unit-tested against a fake.
 */
final class RecentlyAddedQuery
{
    /**
     * How many videos to return when the caller doesn't ask for a specific number.
     */
    private const DEFAULT_LIMIT = 12;

    /**
     * How long a computed listing stays cached — the backstop for the
     * (harmless) case the explicit purge-on-publish is somehow missed.
     */
    private const CACHE_TTL_SECONDS = 900;

    /**
     * Must match `Tube_Cache\Cache\CacheKeys::recently_added()` exactly
     * — see `RelatedVideosFinder`'s docblock for why tube-search can't
     * reference that method directly.
     */
    private const CACHE_KEY = 'recently_added';

    /**
     * Construct around the collaborators this query reads and caches through.
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
     * The most recently published videos.
     *
     * @param int $limit Maximum number of videos to return.
     *
     * @return list<SearchIndexRow>
     */
    public function get(int $limit = self::DEFAULT_LIMIT): array
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached)) {
            // The cache round-trips exactly what this method writes below.
            /** @var list<SearchIndexRow> $cached */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
            return $cached;
        }

        $result = $this->repository->recently_added($limit);

        $this->cache->set(self::CACHE_KEY, $result, self::CACHE_TTL_SECONDS);

        return $result;
    }
}
