<?php
/**
 * `POST /tube/v1/auth/login`.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Auth;

use Tube_Members\Email\EmailVerificationService;
use Tube_Members\Support\ClientIp;
use Tube_Members\Support\Nonces;
use WP_REST_Request;
use WP_REST_Response;
use Tube_Members\Support\Params;

/**
 * `POST /tube/v1/auth/login` — the login modal's email/password submit.
 */
final class LoginController
{
    /**
     * Construct around the collaborators this controller authenticates through.
     *
     * @param LoginService             $service            Validates credentials.
     * @param AuthSessionService       $auth_session       Establishes the real WordPress session on success.
     * @param EmailVerificationService $email_verification Reports this account's verification state in the response.
     */
    public function __construct(
        private readonly LoginService $service,
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

        $login    = Params::string($request->get_param('login'));
        $password = Params::string($request->get_param('password'));
        $remember = Params::bool($request->get_param('remember'));

        try {
            $user = $this->service->authenticate($login, $password, ClientIp::resolve());
        } catch (ValidationException $exception) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'errors'  => $exception->errors(),
                ],
                401
            );
        }

        $this->auth_session->log_in($user, $remember);

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
