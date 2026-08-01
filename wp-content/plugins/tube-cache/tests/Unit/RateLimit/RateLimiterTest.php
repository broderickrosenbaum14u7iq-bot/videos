<?php
/**
 * Unit tests for RateLimiter.
 *
 * @package Tube_Cache
 */

declare(strict_types=1);

namespace Tube_Cache\Tests\Unit\RateLimit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tube_Cache\RateLimit\RateLimiter;
use Tube_Cache\Tests\Unit\Cache\Fixtures\InMemoryCache;

/**
 * Exercises RateLimiter's fixed-window logic against a fake CacheInterface — no live Redis.
 */
final class RateLimiterTest extends TestCase
{
    /**
     * The fake cache the limiter under test is wired to.
     *
     * @var InMemoryCache
     */
    private InMemoryCache $cache;

    /**
     * The rate limiter under test.
     *
     * @var RateLimiter
     */
    private RateLimiter $rate_limiter;

    /**
     * Build a fresh limiter and fake cache for each test.
     */
    protected function setUp(): void
    {
        $this->cache        = new InMemoryCache();
        $this->rate_limiter = new RateLimiter($this->cache);
    }

    /**
     * An attempt within the limit is allowed.
     */
    public function test_attempt_within_the_limit_is_allowed(): void
    {
        self::assertTrue($this->rate_limiter->attempt('ip:127.0.0.1', 5, 60));
    }

    /**
     * Attempts up to and including the limit are all allowed.
     */
    public function test_every_attempt_up_to_the_limit_is_allowed(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($this->rate_limiter->attempt('ip:127.0.0.1', 5, 60), "Attempt {$i} should be allowed.");
        }
    }

    /**
     * The attempt that exceeds the limit is rejected.
     */
    public function test_attempt_exceeding_the_limit_is_rejected(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->rate_limiter->attempt('ip:127.0.0.1', 5, 60);
        }

        self::assertFalse($this->rate_limiter->attempt('ip:127.0.0.1', 5, 60));
    }

    /**
     * Different keys are rate-limited independently.
     */
    public function test_different_keys_are_independent(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->rate_limiter->attempt('ip:127.0.0.1', 5, 60);
        }

        self::assertTrue($this->rate_limiter->attempt('ip:10.0.0.1', 5, 60));
    }

    /**
     * A non-positive $max_attempts is rejected.
     */
    public function test_rejects_non_positive_max_attempts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->rate_limiter->attempt('ip:127.0.0.1', 0, 60);
    }

    /**
     * A non-positive $window_seconds is rejected.
     */
    public function test_rejects_non_positive_window_seconds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->rate_limiter->attempt('ip:127.0.0.1', 5, 0);
    }
}
