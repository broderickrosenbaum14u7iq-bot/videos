<?php
/**
 * Test fixture: an in-memory CacheInterface.
 *
 * @package Tube_Cache
 */

declare(strict_types=1);

namespace Tube_Cache\Tests\Unit\Cache\Fixtures;

use Tube_Cache\Cache\CacheInterface;

/**
 * An in-memory CacheInterface that behaves like a real fixed-window Redis
 * counter (real wall-clock expiry, not a step-counted fake) — this is
 * what makes RateLimiter and CachePurgeSubscriber unit-testable without a
 * live Redis connection.
 *
 * Counters (written only by increment()) are tracked in their own
 * int-typed map, separate from arbitrary get()/set() values — this
 * project's real key-naming conventions never mix the two for the same
 * key (video_detail:* vs. rate-limiter keys), so this split costs no
 * fidelity while keeping each map's value type exact instead of `mixed`.
 */
final class InMemoryCache implements CacheInterface
{
    /**
     * Every key this fake has ever been asked to delete, in call order —
     * lets a test assert exactly what was purged without inspecting
     * internal storage.
     *
     * @var list<string>
     */
    public array $deleted = [];

    /**
     * Stored values, keyed by cache key.
     *
     * @var array<string, mixed>
     */
    private array $values = [];

    /**
     * Counters, keyed by cache key.
     *
     * @var array<string, int>
     */
    private array $counters = [];

    /**
     * Expiry timestamp per key (values and counters share this map, since
     * a given key is only ever used as one or the other in practice),
     * keyed by cache key.
     *
     * @var array<string, int>
     */
    private array $expires_at = [];

    /**
     * {@inheritDoc}
     *
     * @param string $key The cache key, unprefixed.
     */
    public function get(string $key): mixed
    {
        if (! $this->is_live($key) || ! array_key_exists($key, $this->values)) {
            return null;
        }

        return $this->values[ $key ];
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
        $this->values[ $key ]     = $value;
        $this->expires_at[ $key ] = time() + $ttl_seconds;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $key The cache key, unprefixed.
     */
    public function delete(string $key): void
    {
        $this->deleted[] = $key;

        unset($this->values[ $key ], $this->counters[ $key ], $this->expires_at[ $key ]);
    }

    /**
     * {@inheritDoc}
     *
     * @param string $key         The counter key, unprefixed.
     * @param int    $ttl_seconds Window length in seconds, applied only when the counter is created.
     */
    public function increment(string $key, int $ttl_seconds): int
    {
        if (! $this->is_live($key) || ! array_key_exists($key, $this->counters)) {
            $this->counters[ $key ]   = 0;
            $this->expires_at[ $key ] = time() + $ttl_seconds;
        }

        ++$this->counters[ $key ];

        return $this->counters[ $key ];
    }

    /**
     * Whether $key is currently present and not yet expired.
     *
     * @param string $key The cache key, unprefixed.
     */
    private function is_live(string $key): bool
    {
        if (! array_key_exists($key, $this->expires_at)) {
            return false;
        }

        return $this->expires_at[ $key ] >= time();
    }
}
