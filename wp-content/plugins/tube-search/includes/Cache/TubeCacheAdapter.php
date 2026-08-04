<?php
/**
 * Real SearchCacheInterface implementation, backed by tube-cache.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Cache;

use Tube_Cache\Plugin as Tube_Cache_Plugin;

/**
 * Real SearchCacheInterface implementation — a thin pass-through to
 * `Tube_Cache\Plugin::instance()->cache()`, tube-cache's own public
 * Redis-backed cache accessor (Phase 3).
 *
 * Safe to reference `Tube_Cache\Plugin` directly despite tube-search's
 * own composer.json having no dependency on tube-cache's package
 * (DEVELOPMENT_RULES.md §2): this class is never loaded by tube-search's
 * own standalone PHPUnit suite (see `SearchCacheInterface`'s docblock),
 * only inside real WordPress, where `Requires Plugins: tube-core,
 * tube-cache` in `tube-search.php`'s header guarantees tube-cache is
 * active and loaded first — verified live, not unit-tested.
 */
final class TubeCacheAdapter implements SearchCacheInterface
{
    /**
     * {@inheritDoc}
     *
     * @param string $key The cache key, unprefixed.
     */
    public function get(string $key): mixed
    {
        return Tube_Cache_Plugin::instance()->cache()->get($key);
    }

    /**
     * {@inheritDoc}
     *
     * @param string $key         The cache key, unprefixed.
     * @param mixed  $value       The value to store. Must be serializable.
     * @param int    $ttl_seconds Seconds until the entry expires. Must be positive.
     */
    public function set(string $key, mixed $value, int $ttl_seconds): void
    {
        Tube_Cache_Plugin::instance()->cache()->set($key, $value, $ttl_seconds);
    }
}
