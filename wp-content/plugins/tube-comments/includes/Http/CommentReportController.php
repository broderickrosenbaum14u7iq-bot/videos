<?php
/**
 * `POST /tube/v1/comments/{id}/report`.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Http;

use Tube_Comments\Comments\Repositories\CommentReportRepository;
use Tube_Comments\Support\RedisRateLimiter;
use WP_REST_Request;
use WP_REST_Response;
use Tube_Comments\Support\Params;

/**
 * `POST /tube/v1/comments/{id}/report` — report a comment (Phase 17).
 * Never deletes or hides the comment by itself — a report only
 * surfaces it on the moderation screen's "Reported" filter.
 */
final class CommentReportController
{
    private const RATE_LIMIT_MAX_ATTEMPTS   = 10;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    /**
     * The only report reasons the composer's "Báo cáo" menu offers
     * (Phase 17): Spam, Nội dung không phù hợp, Quấy rối, Khác.
     *
     * @var list<string>
     */
    private const VALID_REASONS = ['spam', 'inappropriate', 'harassment', 'other'];

    /**
     * Construct around the collaborators this controller records reports through.
     *
     * @param CommentReportRepository $reports      Records the report.
     * @param RedisRateLimiter        $rate_limiter Bounds report-spamming.
     */
    public function __construct(
        private readonly CommentReportRepository $reports,
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
        $comment_id = Params::int($request->get_param('id'));
        $reason     = Params::string($request->get_param('reason'));
        $user_id    = get_current_user_id();

        // Same verification gate as CommentCreateController, checked
        // before the rate limiter below consumes any quota (2026-08-27
        // email-verification task, Phase 11: "reporting is a
        // moderation-impacting action and should require verified
        // accounts"). Moderators bypass, same capability this
        // controller's sibling already uses for its own anti-spam
        // exemption.
        if (
            ! current_user_can('moderate_comments')
            && function_exists('tube_members_is_email_verified')
            && ! tube_members_is_email_verified($user_id)
        ) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'code'    => 'tube_email_verification_required',
                    'message' => 'Bạn cần xác thực email trước khi báo cáo bình luận.',
                ],
                403
            );
        }

        if (! in_array($reason, self::VALID_REASONS, true)) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error'   => 'Lý do báo cáo không hợp lệ.',
                ],
                422
            );
        }

        $within_limit = $this->rate_limiter->attempt(
            "comment_report:user:{$user_id}",
            self::RATE_LIMIT_MAX_ATTEMPTS,
            self::RATE_LIMIT_WINDOW_SECONDS
        );

        if (! $within_limit) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error'   => 'Bạn thao tác quá nhanh.',
                ],
                429
            );
        }

        $this->reports->add($comment_id, $user_id, $reason);

        // Deliberately always success=true, even when this reporter
        // already reported this comment before (INSERT IGNORE no-op) —
        // per Phase 17, a duplicate report is silently absorbed, not an
        // error the reporter needs to see.
        return new WP_REST_Response(['success' => true], 200);
    }
}
