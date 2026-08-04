<?php
/**
 * Test fixture: an in-memory SearchCacheInterface.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Tests\Unit\Discovery\Fixtures;

use Tube_Search\Cache\SearchCacheInterface;

/**
 * An in-memory SearchCacheInterface — genuinely stores/returns values
 * (not just a call recorder), so tests can prove a second call reuses a
 * cached result instead of recomputing it.
 */
final class InMemorySearchCache implements SearchCacheInterface
{
    /**
     * The current contents of the fake cache, keyed by cache key.
     *
     * @var array<string, mixed>
     */
    private array $store = [];

    /**
     * Every set() call this fake received, in order.
     *
     * @var list<array{key: string, value: mixed, ttl_seconds: int}>
     */
    public array $set_calls = [];

    /**
     * {@inheritDoc}
     *
     * @param string $key The cache key, unprefixed.
     */
    public function get(string $key): mixed
    {
        return $this->store[ $key ] ?? null;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $key         The cache key, unprefixed.
     * @param mixed  $value       The value to store.
     * @param int    $ttl_seconds Seconds until the entry expires.
     */
    public function set(string $key, mixed $value, int $ttl_seconds): void
    {
        $this->store[ $key ] = $value;

        $this->set_calls[] = [
            'key'         => $key,
            'value'       => $value,
            'ttl_seconds' => $ttl_seconds,
        ];
    }
}
