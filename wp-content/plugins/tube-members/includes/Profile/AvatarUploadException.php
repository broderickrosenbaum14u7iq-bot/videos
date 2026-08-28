<?php
/**
 * Thrown when an avatar upload fails validation or processing.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Profile;

use RuntimeException;

/**
 * Thrown by `AvatarService::upload()` with an already-Vietnamese,
 * already-safe-to-display message — see `Tube_Members\Auth\ValidationException`
 * for the same reasoning applied to registration/login.
 */
final class AvatarUploadException extends RuntimeException
{
}
