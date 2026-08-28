<?php
/**
 * Redis-backed fixed-window rate limiter.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Support;

use InvalidArgumentException;
use Predis\ClientInterface;
use Predis\PredisException;

/**
 * Redis-backed fixed-window rate limiter — tube-comments' own copy of
 * `Tube_Core\Support\RedisRateLimiter`'s algorithm, per the same "each
 * plugin owns its own copy of this small algorithm" posture that class's
 * docblock documents (`Tube_Members\Support\RedisRateLimiter` is the
 * identical third copy). Fails open on any Redis failure.
 */
final class RedisRateLimiter
{
    /**
     * The Redis key namespace this limiter's counters live under.
     */
    private const KEY_PREFIX = 'tube_comments:rate_limit:';

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
     * @param string $key            Identifies what is being rate-limited, already scoped by the caller.
     * @param int    $max_attempts   The maximum number of attempts allowed per window. Must be positive.
     * @param int    $window_seconds The window length in seconds. Must be positive.
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
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate production logging for a degraded-but-handled Redis failure.
            error_log('[tube-comments] Redis rate-limit check failed, degrading open: ' . $exception->getMessage());

            return true;
        }

        return $attempts_so_far <= $max_attempts;
    }
}
