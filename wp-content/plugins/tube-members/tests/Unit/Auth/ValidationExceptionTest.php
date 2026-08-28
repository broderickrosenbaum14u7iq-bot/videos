<?php
/**
 * Unit tests for ValidationException.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Tube_Members\Auth\ValidationException;

/**
 * Exercises ValidationException's field-scoped error carrying — the
 * mechanism RegistrationService/LoginService use to hand
 * RegistrationController/LoginController Vietnamese, per-field messages
 * (Phase 5's "clear Vietnamese errors"), never a raw WP_Error.
 */
final class ValidationExceptionTest extends TestCase
{
    /**
     * The exact field-scoped errors given to the constructor come back from errors().
     */
    public function test_carries_the_field_scoped_errors_given_to_it(): void
    {
        $errors = [
            'email'    => 'Email không hợp lệ.',
            'password' => 'Mật khẩu quá ngắn.',
        ];

        $exception = new ValidationException($errors);

        self::assertSame($errors, $exception->errors());
    }

    /**
     * The exception is a RuntimeException with a fixed, generic message.
     */
    public function test_is_a_runtime_exception_with_a_generic_message(): void
    {
        $exception = new ValidationException(['email' => 'Email không hợp lệ.']);

        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertSame('Validation failed.', $exception->getMessage());
    }

    /**
     * An empty errors array is accepted and returned as-is.
     */
    public function test_can_carry_no_errors_at_all(): void
    {
        $exception = new ValidationException([]);

        self::assertSame([], $exception->errors());
    }
}
