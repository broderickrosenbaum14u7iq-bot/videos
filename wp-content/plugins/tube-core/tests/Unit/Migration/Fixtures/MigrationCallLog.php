<?php
/**
 * Test fixture: records up()/down() calls made by fake migrations.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Migration\Fixtures;

/**
 * Records up()/down() calls made by fake migrations.
 *
 * MigrationRunner instantiates migration classes fresh via `new $class()`,
 * so fake migrations can't record calls on instance state — this shared
 * static log is how tests observe what the runner actually did.
 */
final class MigrationCallLog
{
    /**
     * The recorded calls, in the order they happened.
     *
     * @var list<array{version: string, action: string}>
     */
    public static array $calls = [];

    /**
     * Clear the log. Call this in each test's setUp().
     */
    public static function reset(): void
    {
        self::$calls = [];
    }

    /**
     * Record that a fake migration's up() or down() ran.
     *
     * @param string $version The fake migration's version.
     * @param string $action  Either "up" or "down".
     */
    public static function record(string $version, string $action): void
    {
        self::$calls[] = [
            'version' => $version,
            'action'  => $action,
        ];
    }
}
