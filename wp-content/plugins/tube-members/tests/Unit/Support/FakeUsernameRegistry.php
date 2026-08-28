<?php
/**
 * Test-only control surface for the bootstrap's username_exists() stub.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Tests\Unit\Support;

/**
 * Lets a `UniqueLoginTest` case declare which usernames the stubbed
 * `username_exists()` (see tests/bootstrap.php) should report as already
 * taken, without needing a real database.
 */
final class FakeUsernameRegistry
{
    /**
     * Usernames the stubbed `username_exists()` reports as taken.
     *
     * @var list<string>
     */
    public static array $taken = [];
}
