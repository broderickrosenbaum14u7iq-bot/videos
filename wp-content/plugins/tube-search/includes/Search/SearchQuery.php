<?php
/**
 * Full-text search — the pure logic behind tube_search_query().
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Search;

use Tube_Search\Cache\SearchCacheInterface;
use Tube_Search\Index\DiscoveryRepositoryInterface;
use Tube_Search\Index\SearchIndexRow;

/**
 * Full-text search, per ARCHITECTURE.md §5's `tube_search_query( $args )`
 * — validates/normalizes paging, delegates the actual `FULLTEXT` query
 * to `DiscoveryRepositoryInterface::search()`, and caches the result.
 *
 * Cached with a TTL only, deliberately never purged reactively — see
 * `Tube_Cache\Cache\CacheKeys::search()`'s own docblock: the key space is
 * unbounded (any arbitrary query text), so there is no sensible "purge
 * every possibly-affected search result" reaction to a video changing,
 * the same `ARCHITECTURE.md` §16.2 deep-page philosophy this project
 * already applies elsewhere.
 *
 * Pure orchestration logic — no WordPress, fully unit-tested against a fake.
 */
final class SearchQuery
{
    /**
     * Results per page when the caller doesn't ask for a specific number.
     */
    private const DEFAULT_PER_PAGE = 20;

    /**
     * How long a computed results page stays cached.
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
     * Run a full-text search.
     *
     * @param string $query    The raw search query text.
     * @param int    $page     Which page of results to return (1-indexed).
     * @param int    $per_page Results per page.
     *
     * @return list<SearchIndexRow> Empty if $query is blank after trimming.
     */
    public function search(string $query, int $page = 1, int $per_page = self::DEFAULT_PER_PAGE): array
    {
        $query = trim($query);

        if ('' === $query) {
            return [];
        }

        $page     = max(1, $page);
        $per_page = max(1, $per_page);

        // Must match Tube_Cache\Cache\CacheKeys::search() exactly — see
        // RelatedVideosFinder's docblock for why tube-search can't
        // reference that method directly.
        $cache_key = 'search:' . md5($query) . ':' . $page . ':' . $per_page;
        $cached    = $this->cache->get($cache_key);

        if (is_array($cached)) {
            // Cached as plain arrays (see SearchIndexRow::to_array()'s docblock for why), decoded back here.
            /** @var list<array<string, mixed>> $cached */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
            return array_map(SearchIndexRow::from_array(...), $cached);
        }

        $offset = ($page - 1) * $per_page;
        $result = $this->repository->search($query, $per_page, $offset);

        $cacheable = array_map(static fn (SearchIndexRow $row): array => $row->to_array(), $result);
        $this->cache->set($cache_key, $cacheable, self::CACHE_TTL_SECONDS);

        return $result;
    }
}
