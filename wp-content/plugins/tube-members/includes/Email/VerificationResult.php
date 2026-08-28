<?php
/**
 * The outcome of one email-verification attempt.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Email;

/**
 * The outcome of one {@see EmailVerificationService::verify()} attempt —
 * deliberately distinguishes `AlreadyVerified` from `InvalidToken` (a
 * repeated click on an already-used link must say "already verified,"
 * never "invalid," per the 2026-08-27 email-verification task's own
 * Phase 7), while `InvalidToken`/`UserNotFound` are kept presentationally
 * identical by the template that renders them (Phase 23: avoid user
 * enumeration) even though they're distinct cases here for anyone
 * debugging.
 */
enum VerificationResult: string
{
    case Verified        = 'verified';
    case AlreadyVerified = 'already_verified';
    case InvalidToken    = 'invalid_token';
    case ExpiredToken    = 'expired_token';
    case UserNotFound    = 'user_not_found';
}
