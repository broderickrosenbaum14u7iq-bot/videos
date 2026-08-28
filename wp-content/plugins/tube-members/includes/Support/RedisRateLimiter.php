<?php
/**
 * Redis-backed fixed-window rate limiter.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Support;

use InvalidArgumentException;
use Predis\ClientInterface;
use Predis\PredisException;

/**
 * Redis-backed fixed-window rate limiter — tube-members' own copy of
 * `Tube_Core\Support\RedisRateLimiter`'s algorithm. Not a dependency on
 * that class: `Tube_Core\Plugin`'s own copy is `private` (not part of
 * that class's external API), the same "each plugin owns its own copy
 * of this small algorithm" posture that class's own docblock documents.
 *
 * Fails open on any Redis failure — a degraded Redis must never be able
 * to block a genuine login/registration attempt, only bound abuse when
 * Redis is healthy.
 */
final class RedisRateLimiter
{
    /**
     * The Redis key namespace this limiter's counters live under.
     */
    private const KEY_PREFIX = 'tube_members:rate_limit:';

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
     *                                (e.g. `login:ip:1.2.3.4` or `register:ip:1.2.3.4`).
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
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate production logging for a degraded-but-handled Redis failure, mirroring Tube_Core\Support\RedisRateLimiter's documented fail-open behavior.
            error_log('[tube-members] Redis rate-limit check failed, degrading open: ' . $exception->getMessage());

            return true;
        }

        return $attempts_so_far <= $max_attempts;
    }

    /**
     * Reset a key's counter — used after a successful login to clear a
     * viewer's own failed-attempt counter so a subsequent genuine
     * mistake isn't penalized by attempts that already succeeded past.
     *
     * @param string $key The same key previously passed to {@see self::attempt()}.
     */
    public function reset(string $key): void
    {
        try {
            $this->client->del([self::KEY_PREFIX . $key]);
        } catch (PredisException $exception) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate production logging for a degraded-but-handled Redis failure.
            error_log('[tube-members] Redis rate-limit reset failed: ' . $exception->getMessage());
        }
    }
}
