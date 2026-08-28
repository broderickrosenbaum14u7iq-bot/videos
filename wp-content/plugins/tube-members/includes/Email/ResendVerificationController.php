<?php
/**
 * `POST /tube/v1/members/me/resend-verification`.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Email;

use Tube_Members\Support\Params;
use Tube_Members\Support\RedisRateLimiter;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /tube/v1/members/me/resend-verification` — Phase 17/18. Gated by
 * `is_user_logged_in()` at route registration; the requester is always
 * the current account, never a target id (no user-enumeration surface).
 *
 * Two independent limits, per the task's own suggested defaults:
 * - 60 seconds between any two sends for one account (checked directly
 *   against a stored "last sent" timestamp, not Redis, so its precise
 *   `retry_after` needs no new capability added to the shared rate
 *   limiter — see this class's own use of {@see self::META_LAST_SENT_AT}).
 * - 5 sends per rolling 24h (the existing `RedisRateLimiter`, the same
 *   fixed-window algorithm/fail-open posture every other rate-limited
 *   endpoint in this project already uses — no new abuse-prevention
 *   infrastructure invented for this, per Phase 18's own instruction).
 */
final class ResendVerificationController
{
    private const META_LAST_SENT_AT = 'tube_email_verification_last_sent_at';

    private const COOLDOWN_SECONDS = 60;

    private const DAILY_MAX_ATTEMPTS   = 5;
    private const DAILY_WINDOW_SECONDS = DAY_IN_SECONDS;

    /**
     * Construct around the collaborators this controller resends through.
     *
     * @param EmailVerificationService $service      Generates/sends the new token+email.
     * @param RedisRateLimiter         $rate_limiter Bounds the rolling-24h send count.
     */
    public function __construct(
        private readonly EmailVerificationService $service,
        private readonly RedisRateLimiter $rate_limiter
    ) {
    }

    /**
     * The route callback.
     *
     * @param WP_REST_Request $request The incoming request (unused; the requester is always the current user).
     */
    public function handle(WP_REST_Request $request): WP_REST_Response // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required by the route callback signature.
    {
        $user = wp_get_current_user();

        if ($this->service->is_verified($user)) {
            return new WP_REST_Response(
                [
                    'success' => true,
                    'message' => 'Email của bạn đã được xác thực.',
                ],
                200
            );
        }

        $last_sent_at = Params::int(get_user_meta($user->ID, self::META_LAST_SENT_AT, true));
        $elapsed      = time() - $last_sent_at;

        if ($last_sent_at > 0 && $elapsed < self::COOLDOWN_SECONDS) {
            $retry_after = self::COOLDOWN_SECONDS - $elapsed;

            return new WP_REST_Response(
                [
                    'success'     => false,
                    'code'        => 'tube_email_verification_rate_limited',
                    'message'     => sprintf(
                        'Bạn vừa yêu cầu email xác thực. Vui lòng thử lại sau %d giây.',
                        $retry_after
                    ),
                    'retry_after' => $retry_after,
                ],
                429
            );
        }

        $within_daily_limit = $this->rate_limiter->attempt(
            "email_verify_resend:{$user->ID}",
            self::DAILY_MAX_ATTEMPTS,
            self::DAILY_WINDOW_SECONDS
        );

        if (! $within_daily_limit) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'code'    => 'tube_email_verification_rate_limited',
                    'message' => 'Bạn đã yêu cầu quá nhiều email xác thực. Vui lòng thử lại sau.',
                ],
                429
            );
        }

        update_user_meta($user->ID, self::META_LAST_SENT_AT, time());

        $sent = $this->service->send_new_verification_email($user);

        if (! $sent) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'code'    => 'tube_email_verification_send_failed',
                    'message' => 'Không thể gửi email xác thực lúc này. Vui lòng thử lại sau.',
                ],
                200
            );
        }

        return new WP_REST_Response(
            [
                'success' => true,
                'message' => 'Đã gửi lại email xác thực.',
            ],
            200
        );
    }
}
