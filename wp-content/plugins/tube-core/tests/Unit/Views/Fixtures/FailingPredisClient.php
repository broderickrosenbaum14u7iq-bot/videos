<?php
/**
 * Test fixture: a Predis client that always throws a configured exception.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Views\Fixtures;

use Predis\ClientInterface;
use Predis\Command\CommandInterface;
use Predis\Configuration\OptionsInterface;
use Predis\Connection\ConnectionInterface;
use Predis\PredisException;
use RuntimeException;

/**
 * A `Predis\ClientInterface` that throws a caller-configured exception on
 * every Redis command — used to verify `RedisViewCounter::record()`
 * degrades gracefully (Phase 11) for every `PredisException` subtype,
 * not only `Predis\Connection\ConnectionException`. Not shared with
 * `tube-cache`'s own identical fixture — test code isn't shared across
 * plugin boundaries in this project, only real, autoloaded classes are
 * (the same "no plugin's composer.json requires another plugin's
 * package" rule this codebase applies everywhere else).
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
     * Not used by RedisViewCounter; always throws.
     *
     * @throws RuntimeException Always.
     */
    public function getCommandFactory()
    {
        throw new RuntimeException('Not used by RedisViewCounter.');
    }

    /**
     * Not used by RedisViewCounter; always throws.
     *
     * @throws RuntimeException Always.
     */
    public function getOptions()
    {
        throw new RuntimeException('Not used by RedisViewCounter.');
    }

    /**
     * Not used by RedisViewCounter; always throws.
     *
     * @return void
     *
     * @throws RuntimeException Always.
     */
    public function connect()
    {
        throw new RuntimeException('Not used by RedisViewCounter.');
    }

    /**
     * Not used by RedisViewCounter; always throws.
     *
     * @return void
     *
     * @throws RuntimeException Always.
     */
    public function disconnect()
    {
        throw new RuntimeException('Not used by RedisViewCounter.');
    }

    /**
     * Not used by RedisViewCounter; always throws.
     *
     * @throws RuntimeException Always.
     */
    public function getConnection()
    {
        throw new RuntimeException('Not used by RedisViewCounter.');
    }

    /**
     * Not used by RedisViewCounter; always throws.
     *
     * @param string       $method    Command ID.
     * @param array<mixed> $arguments Arguments for the command.
     *
     * @throws RuntimeException Always.
     */
    public function createCommand($method, $arguments = [])
    {
        throw new RuntimeException('Not used by RedisViewCounter.');
    }

    /**
     * Not used by RedisViewCounter; always throws.
     *
     * @param CommandInterface $command Command instance.
     *
     * @throws RuntimeException Always.
     */
    public function executeCommand(CommandInterface $command)
    {
        throw new RuntimeException('Not used by RedisViewCounter.');
    }
}
