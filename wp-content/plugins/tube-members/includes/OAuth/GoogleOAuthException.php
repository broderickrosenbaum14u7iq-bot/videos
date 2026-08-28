<?php
/**
 * Thrown when a Google OAuth code exchange or profile fetch fails.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\OAuth;

use RuntimeException;

/**
 * Thrown by `GoogleOAuthClient::exchange_code_for_profile()` on any
 * network, HTTP, or malformed-response failure talking to Google.
 * `GoogleOAuthController` catches this and fails open to the login
 * modal's normal email/password path — a Google outage must never leave
 * a visitor stuck on a dead page (Phase 6).
 */
final class GoogleOAuthException extends RuntimeException
{
}
