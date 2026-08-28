<?php
/**
 * Thrown when a member attempts to edit/delete a comment they do not own.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Comments;

use RuntimeException;

/**
 * Thrown by `CommentService::update()`/`delete()` when the requesting
 * user is not the comment's author (Phase 18/Phase 38's test #20:
 * "other user cannot edit"). Caught by the HTTP controllers and turned
 * into a 403 response — never a stack trace or a generic 500.
 */
final class ForbiddenException extends RuntimeException
{
}
