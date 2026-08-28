<?php
/**
 * Thrown when comment input fails validation.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Comments;

use RuntimeException;

/**
 * Thrown by `CommentService` with an already-Vietnamese,
 * already-safe-to-display message — see
 * `Tube_Members\Auth\ValidationException` for the same reasoning.
 */
final class ValidationException extends RuntimeException
{
}
