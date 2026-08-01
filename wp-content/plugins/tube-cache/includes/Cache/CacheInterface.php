<?php
/**
 * Contract for tube-cache's Redis-backed caching API.
 *
 * @package Tube_Cache
 */

declare(strict_types=1);

namespace Tube_Cache\Cache;

/**
 * Contract for tube-cache's Redis-backed caching API.
 *
 * This is the "caching API" deliverable from ARCHITECTURE.md §12 Phase 3
 * — the boundary tube-player (Phase 6), tube-search (Phase 7), and every
 * other consumer cache computed values behind (video detail lookups,
 * actor/studio lookups, listing query results, per ARCHITECTURE.md §16).
 * RedisCache is the real implementation; a test fake is what makes
 * RateLimiter and CachePurgeSubscriber unit-testable without a live Redis
 * connection — the same interface-justification bar
 * (ARCHITECTURE.md §19.1/§19.5) already applied to HookBusInterface and
 * SchemaVersionRepositoryInterface in tube-core.
 *
 * `get()` returns null for both "not cached" and "cached value was
 * literally null" — nothing in this project's current or planned use of
 * this cache (video/actor/studio/listing lookups, none of which are
 * legitimately null) needs that distinction. Per
 * ARCHITECTURE_FREEZE.md's Flexible Decisions #3, this interface's exact
 * surface area is free to grow later (e.g. a $found by-reference
 * parameter) if a real caller ever needs it — not built speculatively now.
 */
interface CacheInterface
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

    /**
     * Remove a cached value, if present.
     *
     * @param string $key The cache key, unprefixed.
     */
    public function delete(string $key): void;

    /**
     * Increment a counter (the increment itself is atomic), setting its
     * expiry the moment it is first created (i.e. when the returned value
     * is 1) and leaving that expiry untouched on every subsequent
     * increment within the same window. This is a fixed-window counter,
     * the primitive RateLimiter is built on.
     *
     * The increment-then-set-expiry sequence as a whole is not atomic: a
     * process crashing between the two leaves a counter with no expiry.
     * Accepted as a standard, bounded tradeoff for a rate limiter (the
     * industry-standard alternative, a Lua script, trades this away for
     * meaningfully more complexity) — worst case is one stray counter
     * key per crash, not an incorrect rate-limit decision.
     *
     * @param string $key         The counter key, unprefixed.
     * @param int    $ttl_seconds Window length in seconds, applied only when the counter is created. Must be positive.
     *
     * @return int The counter's new value after this increment.
     */
    public function increment(string $key, int $ttl_seconds): int;
}
