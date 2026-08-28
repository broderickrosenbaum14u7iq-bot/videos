<?php
/**
 * Test fixture: an in-memory Predis client simulating a healthy Redis.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Tests\Unit\Support\Fixtures;

use Predis\ClientInterface;
use Predis\Command\CommandInterface;
use RuntimeException;

/**
 * A `Predis\ClientInterface` backed by a plain in-memory array, real
 * enough to exercise `RedisRateLimiter`'s primary (healthy-Redis) path
 * with real `INCR`/`EXPIRE`/`DEL` semantics — no TTL expiry simulation
 * (not needed: `RedisRateLimiterTest`'s window-expiry scenarios use the
 * transient fallback, whose expiry the bootstrap stub already
 * simulates for real). Not shared with `tube-core`'s own equivalent
 * fixtures — test code isn't shared across plugin boundaries in this
 * project, only real, autoloaded classes are.
 */
final class InMemoryPredisClient implements ClientInterface
{
    /**
     * The in-memory counters this fixture's `incr`/`expire`/`del` commands operate on.
     *
     * @var array<string, int>
     */
    private array $counters = [];

    /**
     * Dispatches the three commands `RedisRateLimiter` actually issues.
     *
     * @param string       $method    Command ID (`incr`, `expire`, or `del`).
     * @param array<mixed> $arguments Arguments for the command.
     *
     * @throws RuntimeException If a command other than the three above is called.
     */
    public function __call($method, $arguments)
    {
        switch ($method) {
            case 'incr':
                $key = $arguments[0] ?? null;

                if (! is_string($key)) {
                    throw new RuntimeException('Expected a string key for incr().');
                }

                $this->counters[ $key ] = ($this->counters[ $key ] ?? 0) + 1;

                return $this->counters[ $key ];

            case 'expire':
                // No TTL simulation needed for this fixture's tests — see class docblock.
                return 1;

            case 'del':
                $keys = $arguments[0] ?? null;

                if (! is_array($keys)) {
                    throw new RuntimeException('Expected an array of keys for del().');
                }

                foreach ($keys as $key) {
                    if (is_string($key)) {
                        unset($this->counters[ $key ]);
                    }
                }

                return 1;

            default:
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal test-fixture exception message, never rendered to a browser.
                throw new RuntimeException("Unexpected command for this fixture: {$method}");
        }
    }

    /**
     * Reads back a counter's current value, for test assertions.
     *
     * @param string $key The counter's key.
     *
     * @return int The counter's current value, or 0 if never incremented.
     */
    public function counterValue(string $key): int
    {
        return $this->counters[ $key ] ?? 0;
    }

    /**
     * Not used by RedisRateLimiter; always throws.
     *
     * @throws RuntimeException Always.
     */
    public function getCommandFactory()
    {
        throw new RuntimeException('Not used by RedisRateLimiter.');
    }

    /**
     * Not used by RedisRateLimiter; always throws.
     *
     * @throws RuntimeException Always.
     */
    public function getOptions()
    {
        throw new RuntimeException('Not used by RedisRateLimiter.');
    }

    /**
     * Not used by RedisRateLimiter; always throws.
     *
     * @return void
     *
     * @throws RuntimeException Always.
     */
    public function connect()
    {
        throw new RuntimeException('Not used by RedisRateLimiter.');
    }

    /**
     * Not used by RedisRateLimiter; always throws.
     *
     * @return void
     *
     * @throws RuntimeException Always.
     */
    public function disconnect()
    {
        throw new RuntimeException('Not used by RedisRateLimiter.');
    }

    /**
     * Not used by RedisRateLimiter; always throws.
     *
     * @throws RuntimeException Always.
     */
    public function getConnection()
    {
        throw new RuntimeException('Not used by RedisRateLimiter.');
    }

    /**
     * Not used by RedisRateLimiter; always throws.
     *
     * @param string       $method    Command ID.
     * @param array<mixed> $arguments Arguments for the command.
     *
     * @throws RuntimeException Always.
     */
    public function createCommand($method, $arguments = [])
    {
        throw new RuntimeException('Not used by RedisRateLimiter.');
    }

    /**
     * Not used by RedisRateLimiter; always throws.
     *
     * @param CommandInterface $command Command instance.
     *
     * @throws RuntimeException Always.
     */
    public function executeCommand(CommandInterface $command)
    {
        throw new RuntimeException('Not used by RedisRateLimiter.');
    }
}
