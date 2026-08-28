<?php
/**
 * `POST /tube/v1/videos/{id}/comments`.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Http;

use Tube_Comments\Comments\AntiSpam\SpamLimitException;
use Tube_Comments\Comments\AntiSpam\SpamPolicy;
use Tube_Comments\Comments\CommentService;
use Tube_Comments\Comments\Repositories\CommentLikeRepository;
use Tube_Comments\Comments\Repositories\CommentRootLockRepository;
use Tube_Comments\Comments\ValidationException;
use Tube_Comments\Support\RedisRateLimiter;
use WP_REST_Request;
use WP_REST_Response;
use Tube_Comments\Support\Params;

/**
 * `POST /tube/v1/videos/{id}/comments` — create a root comment or reply
 * (a `reply_to` body param makes it a reply). Gated by
 * `is_user_logged_in()` at route registration (Phase 12); rate-limited
 * per account per Phase 21's "comments: 5/minute/account, 30/hour/account"
 * (skipped entirely for `moderate_comments` holders, the same exemption
 * `Tube_Comments\Comments\AntiSpam\SpamGuard` grants for the rules it
 * enforces further down in `CommentService::create()`), on top of which
 * `SpamGuard` applies the account-based daily/duplicate/flood rules.
 */
final class CommentCreateController
{
    private const PER_MINUTE_MAX    = 5;
    private const PER_MINUTE_WINDOW = 60;
    private const PER_HOUR_MAX      = 30;
    private const PER_HOUR_WINDOW   = 3600;

    /**
     * Construct around the collaborators this controller creates a comment through.
     *
     * @param CommentService   $service      Validates, flattens replies, and persists.
     * @param RedisRateLimiter $rate_limiter Bounds how often one account may post.
     */
    public function __construct(
        private readonly CommentService $service,
        private readonly RedisRateLimiter $rate_limiter
    ) {
    }

    /**
     * The route callback.
     *
     * @param WP_REST_Request $request The incoming request.
     */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $user_id      = get_current_user_id();
        $is_moderator = current_user_can('moderate_comments');

        // Checked before any rate-limit/anti-spam quota is ever
        // consumed (2026-08-27 email-verification task, Phase 10): a
        // request rejected solely because the account is unverified
        // must not cost the visitor one of their real comment attempts.
        // `function_exists()`-guarded the same way every other
        // tube-members cross-plugin call in this codebase already is
        // (see CommentPresenter's own avatar_url lookup) -- absent
        // tube-members, there is no verification concept to enforce.
        if (
            ! $is_moderator
            && function_exists('tube_members_is_email_verified')
            && ! tube_members_is_email_verified($user_id)
        ) {
            $reply_to = $request->get_param('reply_to');
            $message  = is_numeric($reply_to)
                ? 'Bạn cần xác thực email trước khi trả lời bình luận.'
                : 'Bạn cần xác thực email trước khi bình luận.';

            return new WP_REST_Response(
                [
                    'success' => false,
                    'code'    => 'tube_email_verification_required',
                    'message' => $message,
                ],
                403
            );
        }

        if (! $is_moderator) {
            $within_minute_limit = $this->rate_limiter->attempt(
                "comment:minute:{$user_id}",
                self::PER_MINUTE_MAX,
                self::PER_MINUTE_WINDOW
            );
            $within_hour_limit   = $this->rate_limiter->attempt(
                "comment:hour:{$user_id}",
                self::PER_HOUR_MAX,
                self::PER_HOUR_WINDOW
            );

            if (! $within_minute_limit || ! $within_hour_limit) {
                return new WP_REST_Response(
                    [
                        'success' => false,
                        'error'   => 'Bạn đang bình luận quá nhanh. Vui lòng thử lại sau.',
                    ],
                    429
                );
            }
        }

        $video_id    = Params::int($request->get_param('id'));
        $content     = Params::string($request->get_param('content'));
        $reply_to    = $request->get_param('reply_to');
        $reply_to_id = is_numeric($reply_to) ? (int) $reply_to : null;

        try {
            $row = $this->service->create($video_id, $user_id, $content, $reply_to_id);
        } catch (SpamLimitException $exception) {
            return new WP_REST_Response(
                [
                    'success'      => false,
                    'code'         => $exception->code(),
                    'message'      => $exception->getMessage(),
                    'available_at' => $exception->available_at(),
                    'retry_after'  => $this->retry_after_seconds($exception->available_at()),
                ],
                429
            );
        } catch (ValidationException $exception) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error'   => $exception->getMessage(),
                ],
                422
            );
        }

        $presenter = new CommentPresenter(new CommentLikeRepository());

        $response = [
            'success' => true,
            'comment' => $presenter->present_many([$row], $user_id)[0] ?? null,
        ];

        if (null === $reply_to_id) {
            $root_locks                             = new CommentRootLockRepository();
            $window                                 = SpamPolicy::ROOT_COMMENT_WINDOW_SECONDS;
            $available_at                           = $root_locks->available_at($user_id, $video_id, $window);
            $response['viewer_root_comment_status'] = [
                'blocked'      => null !== $available_at,
                'available_at' => $available_at,
            ];
        }

        return new WP_REST_Response($response, 201);
    }

    /**
     * Seconds from now until $available_at, or null if $available_at is null.
     *
     * @param string|null $available_at ISO 8601 instant, or null.
     */
    private function retry_after_seconds(?string $available_at): ?int
    {
        if (null === $available_at) {
            return null;
        }

        $timestamp = strtotime($available_at);

        if (false === $timestamp) {
            return null;
        }

        return max(1, $timestamp - time());
    }
}
