<?php
/**
 * Test-only control surface for the bootstrap's transient stubs.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Tests\Unit\Support;

/**
 * Backs the stubbed `get_transient()`/`set_transient()`/
 * `delete_transient()` trio (see tests/bootstrap.php) without needing a
 * real database — the same "typed static property as the fake's
 * control surface" convention `FakeUsernameRegistry` already
 * establishes in this same directory, chosen over a raw `$GLOBALS[]`
 * array specifically so PHPStan (level max, this project's own
 * standard) can actually type-check every read/write against it.
 */
final class FakeTransientStore
{
    /**
     * Every currently-stored transient, keyed by its transient name.
     *
     * @var array<string, array{value: string, expires_at: int}>
     */
    public static array $entries = [];
}
