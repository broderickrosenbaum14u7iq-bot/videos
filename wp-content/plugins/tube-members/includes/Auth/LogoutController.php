<?php
/**
 * `POST /tube/v1/auth/logout`.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Auth;

use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /tube/v1/auth/logout` — gated by `is_user_logged_in()` at the
 * route registration level, so core's own cookie-nonce enforcement
 * (`rest_cookie_check_errors()`) already covers CSRF protection here —
 * no manual nonce check needed, unlike the anonymous `/auth/register`/
 * `/auth/login` endpoints.
 */
final class LogoutController
{
    /**
     * Construct around the service that ends the real WordPress session.
     *
     * @param AuthSessionService $auth_session Ends the real WordPress session.
     */
    public function __construct(private readonly AuthSessionService $auth_session)
    {
    }

    /**
     * The route callback.
     *
     * @param WP_REST_Request $request The incoming request (unused; nothing to read).
     */
    public function handle(WP_REST_Request $request): WP_REST_Response // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required by the REST route's callback signature; nothing to read from the request.
    {
        $this->auth_session->log_out();

        return new WP_REST_Response(['success' => true], 200);
    }
}
