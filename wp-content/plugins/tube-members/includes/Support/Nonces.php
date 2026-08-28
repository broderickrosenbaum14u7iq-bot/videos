<?php
/**
 * Shared nonce action names for tube-members' pre-auth endpoints.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Support;

/**
 * Shared nonce action names for tube-members' pre-auth endpoints.
 *
 * `/auth/register` and `/auth/login` are called before a WordPress auth
 * cookie exists, so they cannot rely on core's automatic
 * `rest_cookie_check_errors()` X-WP-Nonce enforcement (that only applies
 * to already-cookie-authenticated requests) — this project's own
 * cross-site-request-forgery protection for those two anonymous
 * endpoints instead. `HeaderAccountRenderer` creates the nonce
 * (`wp_create_nonce(self::AUTH)`) and localizes it into the login modal's
 * script data on every page load; `RegistrationController`/
 * `LoginController` verify it. Every other write endpoint this plugin
 * registers requires `is_user_logged_in()`, which core's own nonce
 * enforcement already covers.
 */
final class Nonces
{
    /**
     * The nonce action shared by the registration and login forms.
     */
    public const AUTH = 'tube_members_auth';
}
