<?php
/**
 * `GET/POST /tube/v1/members/me`.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Profile;

use Tube_Members\Email\EmailVerificationService;
use Tube_Members\Email\VerificationEmailSender;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use Tube_Members\Support\Params;

/**
 * `GET /tube/v1/members/me` (the account page's own data) and
 * `POST /tube/v1/members/me` (display-name update — Phase 9 exposes no
 * other editable profile field). Both gated by `is_user_logged_in()` at
 * route registration.
 */
final class ProfileController
{
    /**
     * `GET` — this member's own profile. Never returns another user's
     * data; `get_current_user_id()` is the only identity ever read.
     *
     * `email_verified` is the only verification-related field exposed
     * here (2026-08-27 email-verification task, Phase 9/34) — never the
     * token, its hash, or its expiry, and this endpoint is already
     * gated to the owner's own account at route registration, so no
     * other visitor's verification state is ever reachable through it.
     *
     * @param WP_REST_Request $request The incoming request (unused; nothing to read).
     */
    public function me(WP_REST_Request $request): WP_REST_Response // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required by the REST route's callback signature; nothing to read from the request.
    {
        $user = wp_get_current_user();

        // Constructed directly rather than injected: ProfileController is
        // instantiated with no constructor args by Tube_Members\Plugin
        // (unlike the controllers that mutate verification state), so a
        // stateless read-only service here matches that existing shape
        // rather than widening this class's own constructor for one field.
        $email_verification = new EmailVerificationService(new VerificationEmailSender());

        return new WP_REST_Response(
            [
                'id'             => $user->ID,
                'display_name'   => $user->display_name,
                'email'          => $user->user_email,
                'avatar_url'     => (new AvatarService())->url_for($user->ID),
                'email_verified' => $email_verification->is_verified($user),
            ],
            200
        );
    }

    /**
     * `POST` — update this member's display name.
     *
     * @param WP_REST_Request $request The incoming request.
     */
    public function update_me(WP_REST_Request $request): WP_REST_Response
    {
        $raw_display_name = Params::string($request->get_param('display_name'));
        $display_name     = trim(wp_strip_all_tags($raw_display_name));

        if ('' === $display_name || mb_strlen($display_name) < 2 || mb_strlen($display_name) > 50) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error'   => 'Tên hiển thị phải có từ 2 đến 50 ký tự.',
                ],
                422
            );
        }

        $user_id = get_current_user_id();

        $result = wp_update_user(
            [
                'ID'           => $user_id,
                'display_name' => $display_name,
                'nickname'     => $display_name,
            ]
        );

        if ($result instanceof WP_Error) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error'   => 'Không thể cập nhật hồ sơ.',
                ],
                500
            );
        }

        return new WP_REST_Response(
            [
                'success'      => true,
                'display_name' => $display_name,
            ],
            200
        );
    }
}
