<?php
/**
 * Test fixture: a Predis client that always throws a configured exception.
 *
 * @package Tube_Cache
 */

declare(strict_types=1);

namespace Tube_Cache\Tests\Unit\Cache\Fixtures;

use Predis\ClientInterface;
use Predis\Command\CommandInterface;
use Predis\Configuration\OptionsInterface;
use Predis\Connection\ConnectionInterface;
use Predis\PredisException;
use RuntimeException;

/**
 * A `Predis\ClientInterface` that throws a caller-configured exception on
 * every Redis command — used to verify `RedisCache` degrades gracefully
 * (Phase 11) for every `PredisException` subtype, not only
 * `Predis\Connection\ConnectionException`. Every real Predis command
 * (`get`/`setex`/`del`/`incr`/`expire`/...) is dispatched through
 * `ClientInterface::__call()`, which this fixture implements directly;
 * the other interface methods are never exercised by `RedisCache` and
 * always throw — they exist only to satisfy the interface.
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
     * Dispatches every real Predis command (get/setex/del/incr/expire/...).
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
     * Not used by RedisCache; always throws.
     *
     * @throws RuntimeException Always.
     */
    public function getCommandFactory()
    {
        throw new RuntimeException('Not used by RedisCache.');
    }

    /**
     * Not used by RedisCache; always throws.
     *
     * @throws RuntimeException Always.
     */
    public function getOptions()
    {
        throw new RuntimeException('Not used by RedisCache.');
    }

    /**
     * Not used by RedisCache; always throws.
     *
     * @return void
     *
     * @throws RuntimeException Always.
     */
    public function connect()
    {
        throw new RuntimeException('Not used by RedisCache.');
    }

    /**
     * Not used by RedisCache; always throws.
     *
     * @return void
     *
     * @throws RuntimeException Always.
     */
    public function disconnect()
    {
        throw new RuntimeException('Not used by RedisCache.');
    }

    /**
     * Not used by RedisCache; always throws.
     *
     * @throws RuntimeException Always.
     */
    public function getConnection()
    {
        throw new RuntimeException('Not used by RedisCache.');
    }

    /**
     * Not used by RedisCache; always throws.
     *
     * @param string       $method    Command ID.
     * @param array<mixed> $arguments Arguments for the command.
     *
     * @throws RuntimeException Always.
     */
    public function createCommand($method, $arguments = [])
    {
        throw new RuntimeException('Not used by RedisCache.');
    }

    /**
     * Not used by RedisCache; always throws.
     *
     * @param CommandInterface $command Command instance.
     *
     * @throws RuntimeException Always.
     */
    public function executeCommand(CommandInterface $command)
    {
        throw new RuntimeException('Not used by RedisCache.');
    }
}
