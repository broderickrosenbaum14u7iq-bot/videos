<?php
/**
 * Redis-backed fixed-window rate limiter.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Support;

use InvalidArgumentException;
use Predis\ClientInterface;
use Predis\PredisException;

/**
 * Redis-backed fixed-window rate limiter — tube-core's own copy of
 * `Tube_Cache\RateLimit\RateLimiter`'s algorithm (`INCR` a per-key
 * counter, `EXPIRE` it only on the first increment of a window), not a
 * dependency on that class: tube-core cannot depend on tube-cache (see
 * `RedisViewCounter`'s docblock for the full plugin-independence
 * reasoning — the same one `Tube_Cache\Events\CachePurgeSubscriber`
 * documents from the other side). Used by `LikeController`/
 * `SaveController` to bound how often one viewer can toggle a like/save,
 * per the mobile watch-page redesign's "rate limiting where appropriate"
 * requirement.
 *
 * Fails open on any Redis failure (returns 0 attempts-so-far, so the
 * caller's own `<= $max_attempts` check always passes) — the same
 * fail-open posture `RedisViewCounter`/`Tube_Cache\Cache\RedisCache`
 * already document: a degraded Redis must never be able to block a
 * genuine like/save action, only bound abuse when Redis is healthy.
 */
final class RedisRateLimiter
{
    /**
     * The Redis key namespace this limiter's counters live under.
     */
    private const KEY_PREFIX = 'tube_core:rate_limit:';

    /**
     * Construct around the Predis client used for every command.
     *
     * @param ClientInterface $client The Predis client to issue commands against.
     */
    public function __construct(private readonly ClientInterface $client)
    {
    }

    /**
     * Record one attempt against $key and report whether it is still
     * within the allowed limit for the current window.
     *
     * @param string $key            Identifies what is being rate-limited, already scoped by the caller
     *                                (e.g. `like:42:user:7` or `like:42:guest:<token>`).
     * @param int    $max_attempts   The maximum number of attempts allowed per window. Must be positive.
     * @param int    $window_seconds The window length in seconds. Must be positive.
     *
     * @return bool True if this attempt is within the limit, false if the limit has been exceeded.
     *
     * @throws InvalidArgumentException If $max_attempts or $window_seconds is not positive.
     */
    public function attempt(string $key, int $max_attempts, int $window_seconds): bool
    {
        if ($max_attempts < 1) {
            throw new InvalidArgumentException('$max_attempts must be a positive number of attempts.');
        }

        if ($window_seconds < 1) {
            throw new InvalidArgumentException('$window_seconds must be a positive number of seconds.');
        }

        $prefixed_key = self::KEY_PREFIX . $key;

        try {
            $attempts_so_far = $this->client->incr($prefixed_key);

            if (1 === $attempts_so_far) {
                $this->client->expire($prefixed_key, $window_seconds);
            }
        } catch (PredisException $exception) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate production logging for a degraded-but-handled Redis failure, mirroring RedisViewCounter's documented fail-open behavior.
            error_log('[tube-core] Redis rate-limit check failed, degrading open: ' . $exception->getMessage());

            return true;
        }

        return $attempts_so_far <= $max_attempts;
    }
}
