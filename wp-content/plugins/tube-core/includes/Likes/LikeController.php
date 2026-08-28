<?php
/**
 * `POST /tube/v1/videos/{id}/like`.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Likes;

use Tube_Core\Content\VideoPostType;
use Tube_Core\Support\RedisRateLimiter;
use Tube_Core\WatchHistory\VisitorToken;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /tube/v1/videos/{id}/like` — toggles the current viewer's like
 * on a video, per the mobile watch-page redesign's real Like system.
 *
 * Public (`permission_callback` is `__return_true`), no nonce: the same
 * "writes only the caller's own state, never reads or exposes another
 * viewer's data" reasoning `Tube_Core\WatchHistory\WatchHistoryController`'s
 * own docblock already documents for its own public, nonce-free
 * endpoint applies identically here — a like/unlike toggle is a
 * self-scoped action, not a cross-user confidentiality/integrity
 * concern. Abuse (a script hammering this endpoint to inflate/deflate a
 * real count) is bounded by `RedisRateLimiter` instead of a nonce, which
 * would only stop a real cross-site forgery, not a scripted direct
 * caller.
 */
final class LikeController
{
    /**
     * Maximum like/unlike toggles one viewer may make per video within {@see self::RATE_LIMIT_WINDOW_SECONDS}.
     */
    private const RATE_LIMIT_MAX_ATTEMPTS = 8;

    /**
     * The rate-limit window, in seconds — generous enough for a genuine rapid double-tap correction,
     * tight enough to bound scripted abuse.
     */
    private const RATE_LIMIT_WINDOW_SECONDS = 10;

    /**
     * Construct around the collaborators this controller delegates to.
     *
     * @param LikeToggleService $toggle_service Performs the actual toggle.
     * @param VisitorToken      $visitor_token  Gets/creates a guest's token.
     * @param RedisRateLimiter  $rate_limiter   Bounds how often one viewer may toggle.
     */
    public function __construct(
        private readonly LikeToggleService $toggle_service,
        private readonly VisitorToken $visitor_token,
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
        $id_param = $request->get_param('id');

        // Belt-and-suspenders: the route's own `(?P<id>\d+)` regex
        // already guarantees this — same posture ViewController/
        // WatchHistoryController already document and apply.
        if (! is_numeric($id_param)) {
            return new WP_REST_Response(['error' => 'Invalid video ID.'], 400);
        }

        $video_id = (int) $id_param;
        $post     = get_post($video_id);

        $is_published_video = $post instanceof WP_Post
            && VideoPostType::POST_TYPE === $post->post_type
            && 'publish' === $post->post_status;

        if (! $is_published_video) {
            return new WP_REST_Response(['error' => 'Unknown video.'], 404);
        }

        $current_user_id = get_current_user_id();
        $user_id         = 0 !== $current_user_id ? $current_user_id : null;
        $visitor_token   = null === $user_id ? $this->visitor_token->get_or_create() : null;

        $rate_limit_key = null !== $user_id
            ? "like:{$video_id}:user:{$user_id}"
            : "like:{$video_id}:guest:{$visitor_token}";

        $within_limit = $this->rate_limiter->attempt(
            $rate_limit_key,
            self::RATE_LIMIT_MAX_ATTEMPTS,
            self::RATE_LIMIT_WINDOW_SECONDS
        );

        if (! $within_limit) {
            return new WP_REST_Response(['error' => 'Too many requests. Please slow down.'], 429);
        }

        $result = $this->toggle_service->toggle($user_id, $visitor_token, $video_id);

        return new WP_REST_Response(
            [
                'success'     => true,
                'liked'       => $result['liked'],
                'likes_total' => $result['likes_total'],
            ],
            200
        );
    }
}
