<?php
/**
 * `POST /tube/v1/auth/reset-password`.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Auth;

use Tube_Members\Email\EmailVerificationService;
use Tube_Members\Support\Nonces;
use Tube_Members\Support\Params;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /tube/v1/auth/reset-password` -- the reset-password landing
 * page's new-password form submit. Logs the visitor in immediately on
 * success (the same "AUTO LOGIN" posture `RegistrationController`/
 * `LoginController` already take), since completing this form is
 * itself a real proof of mailbox ownership -- no reason to make them
 * log in a second time with the password they just set.
 */
final class ResetPasswordController
{
    /**
     * Construct around the collaborators this controller resets a password through.
     *
     * @param PasswordResetService     $service            Validates the key/new password and applies the reset.
     * @param AuthSessionService       $auth_session       Establishes the real WordPress session on success.
     * @param EmailVerificationService $email_verification Reports this account's verification state in the
     *                                                       response, the same shape LoginController's own
     *                                                       response already carries.
     */
    public function __construct(
        private readonly PasswordResetService $service,
        private readonly AuthSessionService $auth_session,
        private readonly EmailVerificationService $email_verification
    ) {
    }

    /**
     * The route callback.
     *
     * @param WP_REST_Request $request The incoming request.
     */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Body param, not the X-WP-Nonce header -- see
        // RegistrationController::handle()'s identical comment for why.
        $nonce = Params::string($request->get_param('_wpnonce'));

        if (1 !== wp_verify_nonce($nonce, Nonces::AUTH)) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'errors'  => ['_form' => 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.'],
                ],
                403
            );
        }

        $login                = Params::string($request->get_param('login'));
        $key                  = Params::string($request->get_param('key'));
        $new_password         = Params::string($request->get_param('new_password'));
        $new_password_confirm = Params::string($request->get_param('new_password_confirm'));

        try {
            $user = $this->service->complete_reset($login, $key, $new_password, $new_password_confirm);
        } catch (ValidationException $exception) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'errors'  => $exception->errors(),
                ],
                422
            );
        }

        $this->auth_session->log_in($user, true);

        return new WP_REST_Response(
            [
                'success'    => true,
                'rest_nonce' => wp_create_nonce('wp_rest'),
                'user'       => [
                    'id'             => $user->ID,
                    'display_name'   => $user->display_name,
                    'email'          => $user->user_email,
                    'email_verified' => $this->email_verification->is_verified($user),
                ],
            ],
            200
        );
    }
}
