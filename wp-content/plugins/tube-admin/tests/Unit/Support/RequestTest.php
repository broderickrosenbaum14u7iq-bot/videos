<?php
/**
 * Unit tests for Request.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tube_Admin\Support\Request;

/**
 * Exercises Request::string()'s narrowing of the `mixed`-typed values
 * every $_GET/$_POST read hands back — the guard every screen in this
 * plugin relies on for reading a request field without PHPStan level
 * `max` rejecting an unsafe `(string)` cast.
 */
final class RequestTest extends TestCase
{
    /**
     * A genuine string value for an existing key passes through unchanged.
     */
    public function test_string_returns_the_value_for_an_existing_string_key(): void
    {
        self::assertSame('published', Request::string(['status' => 'published'], 'status'));
    }

    /**
     * A missing key falls back to the given default.
     */
    public function test_string_returns_the_fallback_for_a_missing_key(): void
    {
        self::assertSame('all', Request::string([], 'status', 'all'));
    }

    /**
     * A missing key with no explicit fallback falls back to an empty string.
     */
    public function test_string_defaults_the_fallback_to_an_empty_string(): void
    {
        self::assertSame('', Request::string([], 'status'));
    }

    /**
     * A non-string value (e.g. PHP's native nested-array parsing of a
     * request field) falls back rather than being cast, since casting an
     * array to string would emit a warning/produce "Array", not a usable
     * value.
     */
    public function test_string_falls_back_for_a_non_string_value(): void
    {
        self::assertSame('all', Request::string(['status' => ['nested']], 'status', 'all'));
        self::assertSame('all', Request::string(['status' => null], 'status', 'all'));
    }
}
