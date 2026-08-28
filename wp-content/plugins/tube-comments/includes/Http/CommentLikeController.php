<?php
/**
 * `POST /tube/v1/comments/{id}/like`.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Http;

use Tube_Comments\Comments\Repositories\CommentLikeRepository;
use Tube_Comments\Comments\Repositories\CommentRepository;
use Tube_Comments\Support\RedisRateLimiter;
use WP_REST_Request;
use WP_REST_Response;
use Tube_Comments\Support\Params;

/**
 * `POST /tube/v1/comments/{id}/like` — toggle the requester's like on a
 * comment (Phase 16). A comment like is its own domain object, entirely
 * separate from `Tube_Core\Likes` (video likes) — a different table
 * (`wp_tube_comment_likes`), a different counter column
 * (`wp_tube_comments.likes_total`).
 */
final class CommentLikeController
{
    private const RATE_LIMIT_MAX_ATTEMPTS   = 20;
    private const RATE_LIMIT_WINDOW_SECONDS = 30;

    /**
     * Construct around the collaborators this controller toggles a like through.
     *
     * @param CommentLikeRepository $likes        The per-viewer comment-like rows.
     * @param CommentRepository     $comments     Keeps `likes_total` in sync.
     * @param RedisRateLimiter      $rate_limiter Bounds burst like/unlike abuse (Phase 21).
     */
    public function __construct(
        private readonly CommentLikeRepository $likes,
        private readonly CommentRepository $comments,
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
        $user_id    = get_current_user_id();

        if (null === $this->comments->find($comment_id)) {
            return new WP_REST_Response(['error' => 'Không tìm thấy bình luận.'], 404);
        }

        $within_limit = $this->rate_limiter->attempt(
            "comment_like:{$comment_id}:user:{$user_id}",
            self::RATE_LIMIT_MAX_ATTEMPTS,
            self::RATE_LIMIT_WINDOW_SECONDS
        );

        if (! $within_limit) {
            return new WP_REST_Response(['error' => 'Bạn thao tác quá nhanh. Vui lòng thử lại sau.'], 429);
        }

        if ($this->likes->has_liked($user_id, $comment_id)) {
            if ($this->likes->remove($user_id, $comment_id)) {
                $this->comments->decrement_likes($comment_id);
            }

            $liked = false;
        } else {
            if ($this->likes->add($user_id, $comment_id)) {
                $this->comments->increment_likes($comment_id);
            }

            $liked = true;
        }

        $row = $this->comments->find($comment_id);

        return new WP_REST_Response(
            [
                'success'     => true,
                'liked'       => $liked,
                'likes_total' => null === $row ? 0 : Params::int($row['likes_total']),
            ],
            200
        );
    }
}
