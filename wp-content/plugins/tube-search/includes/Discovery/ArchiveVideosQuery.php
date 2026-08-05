<?php
/**
 * Category/tag/actor/studio archive listing — one page of videos.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Discovery;

use Tube_Search\Cache\SearchCacheInterface;
use Tube_Search\Index\CandidateColumn;
use Tube_Search\Index\DiscoveryRepositoryInterface;

/**
 * Category/tag/actor/studio archive listing, per ARCHITECTURE.md §15.1's
 * four archive page types — one class, not four near-identical ones:
 * every archive is the same query shape (`DiscoveryRepositoryInterface::
 * list_by_column()`/`count_by_column()` against one `CandidateColumn`),
 * differing only in which column, the same "one class, not near-
 * identical ones" reasoning `PopularVideosQuery` already established in
 * Phase 7.
 *
 * Cached with a TTL only, deliberately never purged reactively — a
 * deliberate simplification of ARCHITECTURE.md §16.1's fuller per-event
 * purge matrix (which would purge page 1 of every affected archive on
 * publish/update/delete), chosen per this phase's explicit "keep it
 * simple, no enterprise patterns" instruction: reactive purging here
 * would need old-vs-new taxonomy/actor/studio-membership diffing that
 * nothing in this codebase currently tracks. The same §16.2 "deep pages
 * are eventually consistent within a bounded TTL" philosophy already
 * applied to `SearchQuery`, extended here to every page of every archive
 * rather than only page 2+. See `PHASE-8.md` for the full reasoning.
 *
 * Pure orchestration logic — no WordPress, fully unit-tested against fakes.
 */
final class ArchiveVideosQuery
{
    /**
     * Results per page when the caller doesn't ask for a specific number.
     */
    private const DEFAULT_PER_PAGE = 24;

    /**
     * How long a computed archive page stays cached.
     */
    private const CACHE_TTL_SECONDS = 900;

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
     * Fetch one page of an archive listing.
     *
     * @param CandidateColumn $column   Which archive type — category/tag/actor/studio.
     * @param int             $id       The category/tag/actor/studio ID.
     * @param int             $page     Which page of results to return (1-indexed).
     * @param int             $per_page Results per page.
     */
    public function get(
        CandidateColumn $column,
        int $id,
        int $page = 1,
        int $per_page = self::DEFAULT_PER_PAGE
    ): ArchivePage {
        $page     = max(1, $page);
        $per_page = max(1, $per_page);

        $cache_key = $column->value . ':' . $id . ':' . $page . ':' . $per_page;
        $cached    = $this->cache->get($cache_key);

        if (is_array($cached)) {
            // Cached as a plain array (see SearchIndexRow::to_array()'s docblock for why), decoded back here.
            /** @var array<string, mixed> $cached */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
            return ArchivePage::from_array($cached);
        }

        $offset = ($page - 1) * $per_page;

        $result = new ArchivePage(
            $this->repository->list_by_column($column, $id, $per_page, $offset),
            $this->repository->count_by_column($column, $id),
            $page,
            $per_page
        );

        $this->cache->set($cache_key, $result->to_array(), self::CACHE_TTL_SECONDS);

        return $result;
    }
}
