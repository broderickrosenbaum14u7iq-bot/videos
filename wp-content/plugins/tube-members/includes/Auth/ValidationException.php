<?php
/**
 * Thrown when registration/login input fails validation.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Auth;

use RuntimeException;

/**
 * Thrown by `RegistrationService`/`LoginService` when input fails
 * validation, carrying field-scoped, already-Vietnamese, already-safe-
 * to-display messages (Phase 5's "clear Vietnamese errors") — never a
 * raw WordPress `WP_Error`, whose messages are English and not
 * guaranteed safe for direct frontend display.
 */
final class ValidationException extends RuntimeException
{
    /**
     * Construct with the field-scoped error messages this exception carries.
     *
     * @param array<string, string> $errors Field name => Vietnamese error message.
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Validation failed.');
    }

    /**
     * The field-scoped error messages.
     *
     * @return array<string, string> Field name => Vietnamese error message.
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
