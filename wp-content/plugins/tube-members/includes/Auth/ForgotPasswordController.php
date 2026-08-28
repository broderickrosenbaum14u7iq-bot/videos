<?php
/**
 * `POST /tube/v1/auth/forgot-password`.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Auth;

use Tube_Members\Support\ClientIp;
use Tube_Members\Support\Nonces;
use Tube_Members\Support\Params;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /tube/v1/auth/forgot-password` -- the "Quên mật khẩu?" form's
 * submit. Always returns the same generic success message regardless
 * of whether the submitted login/email actually matches an account
 * (avoids user enumeration, per this endpoint's own requirement) --
 * the one exception is a genuine rate-limit breach, which is reported
 * honestly so a real visitor being throttled isn't left guessing why
 * nothing arrived.
 */
final class ForgotPasswordController
{
    /**
     * Construct around the collaborator this controller requests a reset through.
     *
     * @param PasswordResetService $service Validates, rate-limits, and sends the reset email.
     */
    public function __construct(private readonly PasswordResetService $service)
    {
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

        $login = Params::string($request->get_param('login'));

        try {
            $this->service->request_reset($login, ClientIp::resolve());
        } catch (ValidationException $exception) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'errors'  => $exception->errors(),
                ],
                429
            );
        }

        return new WP_REST_Response(
            [
                'success' => true,
                'message' => 'Nếu tài khoản tồn tại, chúng tôi đã gửi email hướng dẫn đặt lại mật khẩu.',
            ],
            200
        );
    }
}
