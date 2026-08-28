<?php
/**
 * Unit tests for RedisRateLimiter.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Predis\ClientException;
use Tube_Members\Support\RedisRateLimiter;
use Tube_Members\Tests\Unit\Support\Fixtures\FailingPredisClient;
use Tube_Members\Tests\Unit\Support\Fixtures\InMemoryPredisClient;
use Tube_Members\Tests\Unit\Support\FakeTransientStore;

/**
 * Regression coverage for the 2026-08-28 P0 CRIT-2 fix: a Redis outage
 * must never remove the login/registration rate limit entirely. Covers
 * both the unchanged healthy-Redis path and the new fail-closed
 * transient fallback.
 */
final class RedisRateLimiterTest extends TestCase
{
    /**
     * Reset the fake transient store so tests don't leak state into each other.
     */
    protected function setUp(): void
    {
        FakeTransientStore::$entries = [];
    }

    /**
     * A healthy Redis backend enforces max_attempts exactly as before this fix.
     */
    public function test_healthy_redis_enforces_the_limit_unchanged(): void
    {
        $limiter = new RedisRateLimiter(new InMemoryPredisClient());

        self::assertTrue($limiter->attempt('login:ip:1.2.3.4', 3, 900));
        self::assertTrue($limiter->attempt('login:ip:1.2.3.4', 3, 900));
        self::assertTrue($limiter->attempt('login:ip:1.2.3.4', 3, 900));
        // The 4th attempt exceeds max_attempts=3 -- must be rejected.
        self::assertFalse($limiter->attempt('login:ip:1.2.3.4', 3, 900));
    }

    /**
     * Resetting against a healthy Redis backend still clears the counter.
     */
    public function test_healthy_redis_reset_clears_the_counter(): void
    {
        $client  = new InMemoryPredisClient();
        $limiter = new RedisRateLimiter($client);

        $limiter->attempt('login:id:abc', 1, 900);
        self::assertFalse($limiter->attempt('login:id:abc', 1, 900));

        $limiter->reset('login:id:abc');

        self::assertTrue($limiter->attempt('login:id:abc', 1, 900));
    }

    /**
     * The exact CRIT-2 regression check: before the fix, every one of
     * these would have returned true unconditionally (fail-open). After
     * the fix, the transient fallback still bounds attempts.
     */
    public function test_redis_outage_still_enforces_a_limit_not_unlimited_attempts(): void
    {
        $exception = new ClientException('connection refused');
        $limiter   = new RedisRateLimiter(new FailingPredisClient($exception));

        self::assertTrue($limiter->attempt('login:ip:9.9.9.9', 3, 900));
        self::assertTrue($limiter->attempt('login:ip:9.9.9.9', 3, 900));
        self::assertTrue($limiter->attempt('login:ip:9.9.9.9', 3, 900));
        self::assertFalse(
            $limiter->attempt('login:ip:9.9.9.9', 3, 900),
            'a Redis outage must not allow unlimited login attempts'
        );
        self::assertFalse(
            $limiter->attempt('login:ip:9.9.9.9', 3, 900),
            'the limit must stay enforced on every attempt during the outage, not just the first one past it'
        );
    }

    /**
     * The fallback counter must be scoped per rate-limit key, not one
     * shared global counter across every caller.
     */
    public function test_redis_outage_fallback_keys_are_isolated_per_rate_limit_key(): void
    {
        $exception = new ClientException('connection refused');
        $limiter   = new RedisRateLimiter(new FailingPredisClient($exception));

        $limiter->attempt('login:id:victim-a', 1, 900);
        self::assertFalse($limiter->attempt('login:id:victim-a', 1, 900), 'victim-a is now over limit');

        self::assertTrue(
            $limiter->attempt('login:id:victim-b', 1, 900),
            "victim-b has a separate budget and must not be blocked by victim-a's attempts"
        );
    }

    /**
     * A successful login shortly after a Redis outage must still clear
     * whatever counter the transient fallback built up during it.
     */
    public function test_reset_clears_the_transient_fallback_counter(): void
    {
        $exception = new ClientException('connection refused');
        $limiter   = new RedisRateLimiter(new FailingPredisClient($exception));

        $limiter->attempt('login:id:recovering-user', 1, 900);
        self::assertFalse($limiter->attempt('login:id:recovering-user', 1, 900));

        $limiter->reset('login:id:recovering-user');

        self::assertTrue($limiter->attempt('login:id:recovering-user', 1, 900));
    }

    /**
     * The fallback's window expiry behaves the same as the real limiter's.
     */
    public function test_fallback_window_expires_like_the_real_limiter_does(): void
    {
        $exception = new ClientException('connection refused');
        $limiter   = new RedisRateLimiter(new FailingPredisClient($exception));

        self::assertTrue($limiter->attempt('login:ip:5.5.5.5', 1, 1));
        self::assertFalse($limiter->attempt('login:ip:5.5.5.5', 1, 1));

        sleep(2);

        self::assertTrue(
            $limiter->attempt('login:ip:5.5.5.5', 1, 1),
            'a new window must start once the previous one has expired'
        );
    }
}
