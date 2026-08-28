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
 * 2026-08-28 (P0 release remediation, CRIT-2): this class used to fail
 * *open* on any Redis failure, the same posture tube-core's own copy
 * uses for like/save toggle counters. That posture is correct for a
 * vanity metric (a degraded Redis must never block a genuine like) but
 * was wrong here: this is the limiter guarding `LoginService`'s login
 * and `RegistrationService`'s registration attempts, and failing open
 * on a Redis outage meant a Redis blip silently removed brute-force
 * protection from every account at once, with no unlimited-attempts
 * ceiling of any kind.
 *
 * Now: Redis stays the primary backend (unchanged behavior, unchanged
 * performance, whenever Redis is healthy). On a `PredisException`, this
 * degrades to a WordPress-transient-backed counter (`get_transient()`/
 * `set_transient()`, durable in `wp_options`, always available since it
 * doesn't depend on Redis) that enforces the *same* max-attempts/window
 * bound, so a Redis outage can never mean "no limit" — only "a slightly
 * less precise limit." The transient fallback's own read-then-write is
 * not atomic the way Redis `INCR` is, so under concurrent requests
 * during an outage it can under-count by a small margin (two requests
 * racing on the same window could both read the same starting count) --
 * an accepted, documented tradeoff: a best-effort bound during a rare
 * degraded window is a large improvement over no bound at all, and
 * concurrent abuse traffic during that same window would need to win
 * that exact race repeatedly to meaningfully exceed the limit.
 */
final class RedisRateLimiter
{
    /**
     * The Redis key namespace this limiter's counters live under.
     */
    private const KEY_PREFIX = 'tube_members:rate_limit:';

    /**
     * The WordPress transient key namespace the fallback counters live
     * under when Redis is unavailable. `md5()`-hashed together with the
     * caller's own key (not stored verbatim) so this can never exceed
     * `wp_options.option_name`'s length limit regardless of how long a
     * future caller's own rate-limit key gets.
     */
    private const TRANSIENT_PREFIX = 'tube_members_rl_';

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

            return $attempts_so_far <= $max_attempts;
        } catch (PredisException $exception) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate production logging for a degraded Redis failure now falling back to a WordPress-transient-backed counter (never failing open) -- see this class's own docblock.
            error_log('[tube-members] Redis rate-limit check failed, degrading to WordPress transient fallback: ' . $exception->getMessage());

            return $this->attempt_via_transient_fallback($key, $max_attempts, $window_seconds);
        }
    }

    /**
     * The fallback counter used when Redis itself is unreachable — see
     * this class's own docblock for why this exists and its documented
     * concurrency tradeoff versus the primary Redis path.
     *
     * @param string $key            Same meaning as {@see self::attempt()}'s own $key.
     * @param int    $max_attempts   Already validated positive by the caller.
     * @param int    $window_seconds Already validated positive by the caller.
     */
    private function attempt_via_transient_fallback(string $key, int $max_attempts, int $window_seconds): bool
    {
        $transient_key = self::TRANSIENT_PREFIX . md5($key);
        $stored        = get_transient($transient_key);
        // `get_transient()` reads back through `get_option()`, which
        // returns whatever scalar type the value round-tripped through
        // MySQL as -- a stored int is not guaranteed to come back as a
        // PHP int (confirmed live: it came back as the string "1"),
        // only as something numeric. `is_int($stored)` looked correct
        // against this class's own in-memory test fixtures (which never
        // serialize/round-trip anything) but silently failed against a
        // real WordPress install, where every attempt read back
        // "not an int" and reset to 1 -- the rate limit never actually
        // engaged. `is_numeric()` + an explicit cast is the fix,
        // verified against both the unit suite and a real MySQL-backed
        // WordPress environment (see this file's own commit message).
        $attempts_so_far = is_numeric($stored) ? ( (int) $stored) + 1 : 1;

        set_transient($transient_key, $attempts_so_far, $window_seconds);

        return $attempts_so_far <= $max_attempts;
    }

    /**
     * Reset a key's counter — used after a successful login to clear a
     * viewer's own failed-attempt counter so a subsequent genuine
     * mistake isn't penalized by attempts that already succeeded past.
     * Clears both backends unconditionally (not just whichever one
     * `attempt()` last used) so a login that succeeds shortly after a
     * Redis outage recovers still clears any counter the transient
     * fallback built up during the outage.
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

        delete_transient(self::TRANSIENT_PREFIX . md5($key));
    }
}
