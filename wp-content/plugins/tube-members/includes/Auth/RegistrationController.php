<?php
/**
 * `POST /tube/v1/auth/register`.
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
 * `POST /tube/v1/auth/register` — validates and creates a new member
 * account, then auto-logs them in (Phase 5: "AUTO LOGIN, close modal,
 * return user to exact current page, restore pending user action" — the
 * "restore pending action"/"return to current page" half of that is a
 * pure frontend concern the login modal's own JS handles by never
 * navigating away in the first place; this endpoint's job is only to
 * make the account exist and the session real before it responds).
 *
 * 2026-08-27 email-verification task: every new manual registration
 * starts unverified and gets a verification email — but registration
 * SUCCESS never depends on that email actually sending (Phase 4): the
 * account is already created and logged in above this point, so a
 * `wp_mail()` failure here only means `email_sent` comes back `false`
 * for the frontend to offer a "resend" affordance, never a rolled-back
 * account or a failed response.
 */
final class RegistrationController
{
    /**
     * Construct around the collaborators this controller registers a member through.
     *
     * @param RegistrationService      $service            Validates and creates the account.
     * @param AuthSessionService       $auth_session       Logs the new member in immediately after creation.
     * @param EmailVerificationService $email_verification Marks the account unverified and sends its first
     *                                                       verification email.
     */
    public function __construct(
        private readonly RegistrationService $service,
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
        // Body param, never the X-WP-Nonce header: WordPress core's own
        // rest_cookie_check_errors() intercepts that exact header name on
        // every REST request and validates it against the 'wp_rest'
        // action specifically, rejecting the request outright (before
        // this controller ever runs) if it doesn't match -- there is no
        // way to carry a *different* nonce action through that header.
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

        $display_name     = Params::string($request->get_param('display_name'));
        $email            = Params::string($request->get_param('email'));
        $password         = Params::string($request->get_param('password'));
        $password_confirm = Params::string($request->get_param('password_confirm'));

        try {
            $user = $this->service->register(
                $display_name,
                $email,
                $password,
                $password_confirm,
                ClientIp::resolve()
            );
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

        $this->email_verification->mark_unverified_new_registration($user->ID);
        $email_sent = $this->email_verification->send_new_verification_email($user);

        return new WP_REST_Response(
            [
                'success'    => true,
                'rest_nonce' => wp_create_nonce('wp_rest'),
                'email_sent' => $email_sent,
                'user'       => [
                    'id'             => $user->ID,
                    'display_name'   => $user->display_name,
                    'email'          => $user->user_email,
                    'email_verified' => false,
                ],
            ],
            201
        );
    }
}
