<?php
/**
 * Test fixture: a minimal Predis node connection, for building a real ConnectionException.
 *
 * @package Tube_Cache
 */

declare(strict_types=1);

namespace Tube_Cache\Tests\Unit\Cache\Fixtures;

use Predis\Command\CommandInterface;
use Predis\Connection\NodeConnectionInterface;
use Predis\Connection\Parameters;
use Predis\Connection\ParametersInterface;

/**
 * A minimal `Predis\Connection\NodeConnectionInterface` implementation —
 * `Predis\Connection\ConnectionException`'s constructor requires a real
 * connection instance, so this exists purely to satisfy that constructor
 * in tests; none of its methods are ever called.
 */
final class FakeNodeConnection implements NodeConnectionInterface
{
    /**
     * Not used; part of the interface only.
     *
     * @return void
     */
    public function connect()
    {
    }

    /**
     * Not used; part of the interface only.
     *
     * @return void
     */
    public function disconnect()
    {
    }

    /**
     * Not used; part of the interface only.
     */
    public function isConnected()
    {
        return false;
    }

    /**
     * Not used; part of the interface only.
     *
     * @param CommandInterface $command Command instance.
     *
     * @return void
     */
    public function writeRequest(CommandInterface $command)
    {
    }

    /**
     * Not used; part of the interface only.
     *
     * @param CommandInterface $command Command instance.
     */
    public function readResponse(CommandInterface $command)
    {
    }

    /**
     * Not used; part of the interface only.
     *
     * @param CommandInterface $command Command instance.
     */
    public function executeCommand(CommandInterface $command)
    {
    }

    /**
     * Not used; part of the interface only.
     */
    public function getResource()
    {
        return null;
    }

    /**
     * Not used; part of the interface only.
     *
     * @return ParametersInterface
     */
    public function getParameters()
    {
        return new Parameters();
    }

    /**
     * Not used; part of the interface only.
     *
     * @param CommandInterface $command Command instance.
     *
     * @return void
     */
    public function addConnectCommand(CommandInterface $command)
    {
    }

    /**
     * Not used; part of the interface only.
     */
    public function read()
    {
    }

    /**
     * Not used; part of the interface only.
     */
    public function __toString(): string
    {
        return 'fake';
    }
}
