<?php
/**
 * `POST /tube/v1/members/me/password`.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Profile;

use Tube_Members\Auth\AuthSessionService;
use Tube_Members\Support\Params;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /tube/v1/members/me/password` — change the current member's own
 * password. Requires the current password (so a hijacked, still-open
 * session can't be used to lock the real owner out by itself, without at
 * least also knowing the current password).
 *
 * `wp_set_password()` destroys every one of this user's session tokens,
 * including the one behind the current request's own auth cookie (core's
 * own security behavior on a password change) — so a successful change
 * re-establishes the session immediately via `AuthSessionService::log_in()`
 * rather than silently logging the member out of the request they just made.
 */
final class PasswordController
{
    /**
     * Minimum acceptable new-password length, matching `RegistrationService`.
     */
    private const MIN_PASSWORD_LENGTH = 8;

    /**
     * The route callback.
     *
     * @param WP_REST_Request $request The incoming request.
     */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $current_password     = Params::string($request->get_param('current_password'));
        $new_password         = Params::string($request->get_param('new_password'));
        $new_password_confirm = Params::string($request->get_param('new_password_confirm'));

        $user = wp_get_current_user();

        if (! wp_check_password($current_password, $user->user_pass, $user->ID)) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error'   => 'Mật khẩu hiện tại không đúng.',
                ],
                403
            );
        }

        if (
            mb_strlen($new_password) < self::MIN_PASSWORD_LENGTH
            || 1 !== preg_match('/[a-zA-Z]/', $new_password)
            || 1 !== preg_match('/[0-9]/', $new_password)
        ) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error'   => 'Mật khẩu mới phải có ít nhất 8 ký tự, gồm cả chữ và số.',
                ],
                422
            );
        }

        if ($new_password !== $new_password_confirm) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error'   => 'Mật khẩu xác nhận không khớp.',
                ],
                422
            );
        }

        wp_set_password($new_password, $user->ID);

        // wp_set_password() destroyed this request's own session token
        // above -- re-establish it immediately, per this class's docblock.
        $refreshed_user = get_userdata($user->ID);

        if (false !== $refreshed_user) {
            (new AuthSessionService())->log_in($refreshed_user, true);
        }

        // The account page's own page-load nonce is now stale (it was
        // bound to the session token wp_set_password() just destroyed)
        // -- same fix, same reasoning as RegistrationController/
        // LoginController's `rest_nonce`, needed here so a subsequent
        // avatar upload/display-name save on this same page load (no
        // reload) doesn't 403.
        return new WP_REST_Response(
            [
                'success'    => true,
                'rest_nonce' => wp_create_nonce('wp_rest'),
            ],
            200
        );
    }
}
