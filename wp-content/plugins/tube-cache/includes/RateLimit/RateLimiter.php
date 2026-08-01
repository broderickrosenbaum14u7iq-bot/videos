<?php
/**
 * Redis-backed rate-limit helper.
 *
 * @package Tube_Cache
 */

declare(strict_types=1);

namespace Tube_Cache\RateLimit;

use InvalidArgumentException;
use Tube_Cache\Cache\CacheInterface;

/**
 * Redis-backed rate-limit helper, per ARCHITECTURE.md §12 Phase 3.
 *
 * A fixed-window counter built on CacheInterface::increment(): the first
 * call for a given key in a window starts the counter at 1 and sets its
 * expiry to $window_seconds; every subsequent call within that window
 * increments the same counter without touching its expiry. Once the
 * window elapses, Redis expires the key and the next call starts a fresh
 * window — this is the standard, widely-used fixed-window rate-limiting
 * pattern, not a novel design.
 *
 * No dedicated interface: this class has no realistic second
 * implementation or standalone test-fake need of its own (it already
 * unit-tests cleanly against CacheInterface's fake, per
 * ARCHITECTURE.md §19.1's interface-justification bar) — it is a
 * concrete class, the same way MigrationRunner is.
 *
 * No consumer yet in this phase — ARCHITECTURE.md §12 assigns the
 * view-recording endpoint that will call this to Phase 4, and per-API-key
 * rate limiting (§17) to a later mobile-API phase. Building the helper
 * now, ahead of its first caller, is the explicit Phase 3 deliverable
 * itself, the same way Phase 2 built the full event catalog ahead of
 * every event having a real trigger.
 */
final class RateLimiter
{
    /**
     * Construct around the cache backend attempt counts are stored in.
     *
     * @param CacheInterface $cache The cache backend to store attempt counts in.
     */
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    /**
     * Record one attempt against $key and report whether it is still
     * within the allowed limit for the current window.
     *
     * @param string $key            Identifies what is being rate-limited (e.g. an IP
     *                                address or API client ID, already scoped by the caller).
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

        $attempts_so_far = $this->cache->increment($key, $window_seconds);

        return $attempts_so_far <= $max_attempts;
    }
}
