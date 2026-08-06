<?php
/**
 * Unit tests for RedisViewCounter's fail-open behavior.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Views;

use PHPUnit\Framework\TestCase;
use Predis\Connection\ConnectionException;
use Predis\Response\ServerException;
use Tube_Core\Tests\Unit\Views\Fixtures\FailingPredisClient;
use Tube_Core\Tests\Unit\Views\Fixtures\FakeNodeConnection;
use Tube_Core\Views\RedisViewCounter;

/**
 * Exercises `RedisViewCounter::record()`'s degrade-gracefully-on-failure
 * behavior against a fake Predis client — no live Redis. Added Phase 11
 * alongside the fix this test proves: `record()` previously caught only
 * `Predis\Connection\ConnectionException`, silently missing
 * `Predis\Response\ServerException` (Redis's own error responses, e.g.
 * an OOM rejection under memory pressure) — meaning a Redis memory-
 * pressure error on this live-page-render-path method would have thrown
 * uncaught instead of degrading, exactly the scenario this class's own
 * docblock says must never happen. `flush()` is not exercised here — it
 * deliberately does not broadly degrade (see its own docblock); its one
 * narrow, expected `ServerException` race is already covered by
 * `RedisViewCounterIntegrationTest` (or equivalent) against a real Redis.
 */
final class RedisViewCounterTest extends TestCase
{
    /**
     * A connection-level failure degrades record() to a silent no-op.
     */
    public function test_record_degrades_on_connection_exception(): void
    {
        $counter = new RedisViewCounter(new FailingPredisClient(self::connection_exception()));

        $counter->record(42);

        self::addToAssertionCount(1); // Reaching here without a thrown exception is the assertion.
    }

    /**
     * A server-side failure (e.g. Redis OOM) also degrades record() to a silent no-op.
     */
    public function test_record_degrades_on_server_exception(): void
    {
        $counter = new RedisViewCounter(new FailingPredisClient(self::server_exception()));

        $counter->record(42);

        self::addToAssertionCount(1);
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
