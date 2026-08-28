<?php
/**
 * Test fixture: a Predis client that always throws a configured exception.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Tests\Unit\Support\Fixtures;

use Predis\ClientInterface;
use Predis\Command\CommandInterface;
use Predis\PredisException;
use RuntimeException;

/**
 * A `Predis\ClientInterface` that throws a caller-configured exception on
 * every Redis command — used to verify `RedisRateLimiter::attempt()`/
 * `reset()` degrade to the WordPress-transient fallback (2026-08-28, P0
 * CRIT-2 fix) rather than failing open, for every `PredisException`
 * subtype. Not shared with `tube-core`'s own identical-in-spirit
 * fixture — test code isn't shared across plugin boundaries in this
 * project, only real, autoloaded classes are.
 */
final class FailingPredisClient implements ClientInterface
{
    /**
     * Construct the fixture around the exception every command should throw.
     *
     * @param PredisException $exception Thrown by every command call.
     */
    public function __construct(private readonly PredisException $exception)
    {
    }

    /**
     * Dispatches every real Predis command (incr/expire/del/...).
     *
     * @param string       $method    Command ID.
     * @param array<mixed> $arguments Arguments for the command.
     *
     * @throws PredisException Always — see the constructor.
     */
    public function __call($method, $arguments)
    {
        throw $this->exception;
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
