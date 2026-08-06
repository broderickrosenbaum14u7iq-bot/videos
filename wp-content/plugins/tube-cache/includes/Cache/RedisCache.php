<?php
/**
 * Redis-backed CacheInterface implementation.
 *
 * @package Tube_Cache
 */

declare(strict_types=1);

namespace Tube_Cache\Cache;

use InvalidArgumentException;
use Predis\ClientInterface;
use Predis\PredisException;

/**
 * Redis-backed CacheInterface implementation.
 *
 * Every key is namespaced under a fixed prefix so this plugin's entries
 * never collide with anything else sharing the same Redis instance.
 * Values are stored with PHP's serialize()/unserialize() (not JSON) to
 * preserve full fidelity for whatever a caller stores — video detail
 * arrays, actor/studio lookups, listing results — with
 * `allowed_classes: false` on unserialize() specifically so a compromised
 * Redis instance can never smuggle a PHP object-injection payload back
 * into this process; this project stores plain arrays/scalars in the
 * cache, never objects, so disallowing classes costs nothing real.
 *
 * A Redis failure degrades every method to a safe default (documented
 * per-method below) instead of throwing — the cache must never be a
 * single point of failure for page rendering. Each failure is still
 * logged via error_log() so it surfaces through the PHP error-log
 * monitoring ARCHITECTURE.md §18.5 already calls for; it is not
 * swallowed silently. Catches `Predis\PredisException`, the base type
 * both a connection failure (`Predis\Connection\ConnectionException`)
 * and a server-side error (`Predis\Response\ServerException`, e.g.
 * Redis's own `OOM command not allowed when used memory > 'maxmemory'`)
 * extend — widened in Phase 11 after an audit found only
 * `ConnectionException` was caught, so a real, ARCHITECTURE.md
 * §18.5-documented risk (Redis memory pressure on a single, fixed-RAM
 * VPS) was throwing uncaught out of a live page render instead of
 * degrading gracefully like every other Redis failure mode here. A
 * genuine programming error (e.g. a TypeError) is still not masked —
 * `PredisException` is Predis's own base for its exception hierarchy,
 * not PHP's `Throwable`.
 */
final class RedisCache implements CacheInterface
{
    /**
     * Namespaces every key this class touches, so entries never collide
     * with anything else sharing the same Redis instance.
     */
    private const KEY_PREFIX = 'tube_cache:';

    /**
     * Construct around the Predis client used for every command.
     *
     * @param ClientInterface $client The Predis client to issue commands against.
     */
    public function __construct(private readonly ClientInterface $client)
    {
    }

    /**
     * {@inheritDoc}
     *
     * Degrades to a cache miss (null) on a Redis connection failure —
     * indistinguishable from the key genuinely not being cached, which is
     * exactly the safe behavior a caller expects from a cache-aside read.
     *
     * @param string $key The cache key, unprefixed.
     */
    public function get(string $key): mixed
    {
        try {
            $raw = $this->client->get(self::KEY_PREFIX . $key);
        } catch (PredisException $exception) {
            $this->log_redis_failure('get', $exception);

            return null;
        }

        if (null === $raw) {
            return null;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- documented above: allowed_classes=false blocks object-injection, and this project never caches objects, only arrays/scalars.
        return unserialize($raw, ['allowed_classes' => false]);
    }

    /**
     * {@inheritDoc}
     *
     * Degrades to a no-op on a Redis connection failure — the value
     * simply isn't cached this time; the next real read falls through to
     * its uncached path, which is always correct, just slower.
     *
     * @param string $key         The cache key, unprefixed.
     * @param mixed  $value       The value to store. Must be serializable.
     * @param int    $ttl_seconds Seconds until the entry expires. Must be positive.
     *
     * @throws InvalidArgumentException If $ttl_seconds is not positive.
     */
    public function set(string $key, mixed $value, int $ttl_seconds): void
    {
        if ($ttl_seconds < 1) {
            throw new InvalidArgumentException('$ttl_seconds must be a positive number of seconds.');
        }

        try {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- documented on the class: paired with allowed_classes=false on read, and this project never caches objects, only arrays/scalars.
            $this->client->setex(self::KEY_PREFIX . $key, $ttl_seconds, serialize($value));
        } catch (PredisException $exception) {
            $this->log_redis_failure('set', $exception);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Degrades to a no-op on a Redis connection failure — if Redis is
     * unreachable there is nothing to purge, and an un-purged entry will
     * expire on its own TTL regardless.
     *
     * @param string $key The cache key, unprefixed.
     */
    public function delete(string $key): void
    {
        try {
            $this->client->del([self::KEY_PREFIX . $key]);
        } catch (PredisException $exception) {
            $this->log_redis_failure('delete', $exception);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Degrades to returning 0 on a Redis connection failure — a
     * deliberate fail-open choice for rate limiting (RateLimiter treats a
     * low count as "not yet limited"): it is safer for this cache-layer
     * outage to briefly admit more traffic than intended than to make
     * Redis being down also take down every rate-limited endpoint.
     *
     * @param string $key         The counter key, unprefixed.
     * @param int    $ttl_seconds Window length in seconds, applied only when the counter is created. Must be positive.
     *
     * @throws InvalidArgumentException If $ttl_seconds is not positive.
     */
    public function increment(string $key, int $ttl_seconds): int
    {
        if ($ttl_seconds < 1) {
            throw new InvalidArgumentException('$ttl_seconds must be a positive number of seconds.');
        }

        $prefixed_key = self::KEY_PREFIX . $key;

        try {
            $value = $this->client->incr($prefixed_key);

            if (1 === $value) {
                $this->client->expire($prefixed_key, $ttl_seconds);
            }

            return $value;
        } catch (PredisException $exception) {
            $this->log_redis_failure('increment', $exception);

            return 0;
        }
    }

    /**
     * Log a degraded-but-not-fatal Redis failure, per this class's
     * documented fail-open behavior.
     *
     * @param string          $operation The CacheInterface method that failed.
     * @param PredisException $exception The underlying Predis exception (connection or server-side).
     */
    private function log_redis_failure(string $operation, PredisException $exception): void
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate production logging for a degraded-but-handled Redis failure, per this class's documented fail-open behavior and ARCHITECTURE.md §18.5's PHP-error-log monitoring; not leftover debug code.
        error_log(
            sprintf(
                '[tube-cache] Redis %s failed, degrading gracefully: %s',
                $operation,
                $exception->getMessage()
            )
        );
    }
}
