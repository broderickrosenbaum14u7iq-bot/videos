<?php
/**
 * Unit tests for Params.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tube_Comments\Support\Params;

/**
 * Exercises Params's narrowing of the `mixed`-typed values every REST
 * param/row-array read hands back, plus required_sql()'s null-narrowing
 * of `$wpdb->prepare()`'s return — the guards this whole plugin's
 * PHPStan-max cleanup pass relies on being correct.
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
    }

    /**
     * A non-scalar value falls back to an empty string, never a fatal cast error.
     */
    public function test_string_falls_back_to_empty_for_non_scalar(): void
    {
        self::assertSame('', Params::string(null));
        self::assertSame('', Params::string([1, 2, 3]));
    }

    /**
     * A numeric string is parsed to its integer value.
     */
    public function test_int_parses_a_numeric_string(): void
    {
        self::assertSame(42, Params::int('42'));
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
    }

    /**
     * A non-null SQL string passes through required_sql() unchanged.
     */
    public function test_required_sql_returns_a_non_null_string_unchanged(): void
    {
        self::assertSame('SELECT 1', Params::required_sql('SELECT 1'));
    }

    /**
     * A null SQL string (a malformed wpdb::prepare() template) throws rather than propagating null.
     */
    public function test_required_sql_throws_on_null(): void
    {
        $this->expectException(\RuntimeException::class);

        Params::required_sql(null);
    }
}
