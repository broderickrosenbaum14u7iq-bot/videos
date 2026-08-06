<?php
/**
 * Unit tests for RedisCache's fail-open behavior.
 *
 * @package Tube_Cache
 */

declare(strict_types=1);

namespace Tube_Cache\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Predis\Connection\ConnectionException;
use Predis\Response\ServerException;
use Tube_Cache\Cache\RedisCache;
use Tube_Cache\Tests\Unit\Cache\Fixtures\FailingPredisClient;
use Tube_Cache\Tests\Unit\Cache\Fixtures\FakeNodeConnection;

/**
 * Exercises RedisCache's degrade-gracefully-on-failure behavior against
 * a fake Predis client — no live Redis. Added Phase 11 alongside the fix
 * this test proves: every method previously caught only
 * `Predis\Connection\ConnectionException`, silently missing
 * `Predis\Response\ServerException` (Redis's own error responses, e.g.
 * an OOM rejection under memory pressure — a real risk ARCHITECTURE.md
 * §18.5 documents for this project's single, fixed-RAM VPS target). Both
 * exception types are exercised here for every method, confirming
 * neither escapes uncaught.
 */
final class RedisCacheTest extends TestCase
{
    /**
     * A connection-level failure degrades get() to a cache miss (null), not a thrown exception.
     */
    public function test_get_degrades_on_connection_exception(): void
    {
        $cache = new RedisCache(new FailingPredisClient(self::connection_exception()));

        self::assertNull($cache->get('some-key'));
    }

    /**
     * A server-side failure (e.g. Redis OOM) also degrades get() to a cache miss, not a thrown exception.
     */
    public function test_get_degrades_on_server_exception(): void
    {
        $cache = new RedisCache(new FailingPredisClient(self::server_exception()));

        self::assertNull($cache->get('some-key'));
    }

    /**
     * A connection-level failure degrades set() to a silent no-op.
     */
    public function test_set_degrades_on_connection_exception(): void
    {
        $cache = new RedisCache(new FailingPredisClient(self::connection_exception()));

        $cache->set('some-key', 'value', 60);

        self::addToAssertionCount(1); // Reaching here without a thrown exception is the assertion.
    }

    /**
     * A server-side failure also degrades set() to a silent no-op.
     */
    public function test_set_degrades_on_server_exception(): void
    {
        $cache = new RedisCache(new FailingPredisClient(self::server_exception()));

        $cache->set('some-key', 'value', 60);

        self::addToAssertionCount(1);
    }

    /**
     * A connection-level failure degrades delete() to a silent no-op.
     */
    public function test_delete_degrades_on_connection_exception(): void
    {
        $cache = new RedisCache(new FailingPredisClient(self::connection_exception()));

        $cache->delete('some-key');

        self::addToAssertionCount(1);
    }

    /**
     * A server-side failure also degrades delete() to a silent no-op.
     */
    public function test_delete_degrades_on_server_exception(): void
    {
        $cache = new RedisCache(new FailingPredisClient(self::server_exception()));

        $cache->delete('some-key');

        self::addToAssertionCount(1);
    }

    /**
     * A connection-level failure degrades increment() to 0 (fail-open for rate limiting).
     */
    public function test_increment_degrades_to_zero_on_connection_exception(): void
    {
        $cache = new RedisCache(new FailingPredisClient(self::connection_exception()));

        self::assertSame(0, $cache->increment('some-key', 60));
    }

    /**
     * A server-side failure also degrades increment() to 0.
     */
    public function test_increment_degrades_to_zero_on_server_exception(): void
    {
        $cache = new RedisCache(new FailingPredisClient(self::server_exception()));

        self::assertSame(0, $cache->increment('some-key', 60));
    }

    /**
     * A real Predis connection-level exception, built the same way Predis itself constructs one.
     */
    private static function connection_exception(): ConnectionException
    {
        return new ConnectionException(new FakeNodeConnection(), 'Simulated connection failure.');
    }

    /**
     * A real Predis server-side exception (e.g. what an OOM rejection looks like).
     */
    private static function server_exception(): ServerException
    {
        return new ServerException("OOM command not allowed when used memory > 'maxmemory'.");
    }
}
