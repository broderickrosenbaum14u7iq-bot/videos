<?php
/**
 * Unit tests for Params.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tube_Members\Support\Params;

/**
 * Exercises Params's narrowing of the `mixed`-typed values every REST
 * param/superglobal/get_option() read hands back — the guard this whole
 * plugin's PHPStan-max cleanup pass relies on being correct.
 */
final class ParamsTest extends TestCase
{
    /**
     * A genuine string value passes through unchanged.
     */
    public function test_string_passes_through_a_real_string(): void
    {
        self::assertSame('hello', Params::string('hello'));
    }

    /**
     * A scalar non-string value is stringified.
     */
    public function test_string_stringifies_a_scalar(): void
    {
        self::assertSame('42', Params::string(42));
        self::assertSame('1', Params::string(true));
    }

    /**
     * A non-scalar value falls back to an empty string, never a fatal cast error.
     */
    public function test_string_falls_back_to_empty_for_non_scalar(): void
    {
        self::assertSame('', Params::string(null));
        self::assertSame('', Params::string([1, 2, 3]));
        self::assertSame('', Params::string(new \stdClass()));
    }

    /**
     * A numeric string is parsed to its integer value.
     */
    public function test_int_parses_a_numeric_string(): void
    {
        self::assertSame(42, Params::int('42'));
        self::assertSame(-7, Params::int('-7'));
    }

    /**
     * A genuine int value passes through unchanged.
     */
    public function test_int_passes_through_a_real_int(): void
    {
        self::assertSame(42, Params::int(42));
    }

    /**
     * A non-numeric value falls back to zero, never a fatal cast error.
     */
    public function test_int_falls_back_to_zero_for_non_numeric(): void
    {
        self::assertSame(0, Params::int('not-a-number'));
        self::assertSame(0, Params::int(null));
        self::assertSame(0, Params::int([1, 2]));
    }

    /**
     * A truthy scalar value is true.
     */
    public function test_bool_is_true_for_a_truthy_scalar(): void
    {
        self::assertTrue(Params::bool('1'));
        self::assertTrue(Params::bool(1));
        self::assertTrue(Params::bool(true));
    }

    /**
     * A falsy scalar value is false.
     */
    public function test_bool_is_false_for_a_falsy_scalar(): void
    {
        self::assertFalse(Params::bool('0'));
        self::assertFalse(Params::bool(''));
        self::assertFalse(Params::bool(0));
        self::assertFalse(Params::bool(false));
    }

    /**
     * A non-scalar value is false, never a fatal cast error.
     */
    public function test_bool_is_false_for_non_scalar(): void
    {
        self::assertFalse(Params::bool(null));
        self::assertFalse(Params::bool([1]));
    }
}
