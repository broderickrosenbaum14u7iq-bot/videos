<?php
/**
 * Contract tube-search's discovery queries cache expensive results behind.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Cache;

/**
 * Contract tube-search's discovery/query classes
 * (`Tube_Search\Discovery\*`, `Tube_Search\Search\SearchQuery`) cache
 * their expensive results behind, per ARCHITECTURE.md §12 Phase 7's
 * "cache expensive discovery queries" instruction.
 *
 * Tube-search's own interface, not a direct dependency on
 * `Tube_Cache\Cache\CacheInterface` — the same reasoning
 * `Tube_Player\Video\VideoProviderInterface` and every other cross-
 * plugin-coupled dependency in this project already applies: this
 * plugin's own PHPUnit suite has no dependency on tube-cache's package
 * (DEVELOPMENT_RULES.md §2), so the discovery classes that need a real
 * test fake for their cache-hit/cache-miss branches can't type-hint
 * `Tube_Cache\Cache\CacheInterface` directly. `TubeCacheAdapter` is the
 * one thin, WordPress/tube-cache-coupled implementation.
 *
 * Deliberately narrower than `Tube_Cache\Cache\CacheInterface`: no
 * `delete()`/`increment()` — per ARCHITECTURE.md §16, "all purge logic
 * is centralized in tube-cache, which is the only plugin ever allowed
 * to call a cache-purge API"; tube-search only ever reads and writes its
 * own cached results, never purges them itself (`Tube_Cache\Events\
 * CachePurgeSubscriber` does that, using the same `Tube_Cache\Cache\
 * CacheKeys` builders this adapter's real implementation writes under).
 *
 * Adopted per the interface-justification rule (§19.1): the real payoff
 * is a test fake `RelatedVideosFinder`, `PopularVideosQuery`,
 * `RecentlyAddedQuery`, and `SearchQuery` are each unit-tested against.
 */
interface SearchCacheInterface
{
    /**
     * Fetch a cached value.
     *
     * @param string $key The cache key, unprefixed.
     *
     * @return mixed The cached value, or null if not present.
     */
    public function get(string $key): mixed;

    /**
     * Store a value with a fixed expiry.
     *
     * @param string $key         The cache key, unprefixed.
     * @param mixed  $value       The value to store. Must be serializable.
     * @param int    $ttl_seconds Seconds until the entry expires. Must be positive.
     */
    public function set(string $key, mixed $value, int $ttl_seconds): void;
}
