<?php
/**
 * `POST /tube/v1/videos/{id}/save`.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Saves;

use Tube_Core\Content\VideoPostType;
use Tube_Core\Support\RedisRateLimiter;
use Tube_Core\WatchHistory\VisitorToken;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /tube/v1/videos/{id}/save` — toggles the current viewer's
 * "Watch Later" save on a video. Same public/no-nonce/rate-limited shape
 * as `Tube_Core\Likes\LikeController` — see its docblock for the full
 * reasoning, which applies unchanged here.
 */
final class SaveController
{
    /**
     * Maximum save/unsave toggles one viewer may make per video within {@see self::RATE_LIMIT_WINDOW_SECONDS}.
     */
    private const RATE_LIMIT_MAX_ATTEMPTS = 8;

    /**
     * The rate-limit window, in seconds.
     */
    private const RATE_LIMIT_WINDOW_SECONDS = 10;

    /**
     * Construct around the collaborators this controller delegates to.
     *
     * @param SaveToggleService $toggle_service Performs the actual toggle.
     * @param VisitorToken      $visitor_token  Gets/creates a guest's token.
     * @param RedisRateLimiter  $rate_limiter   Bounds how often one viewer may toggle.
     */
    public function __construct(
        private readonly SaveToggleService $toggle_service,
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
            ? "save:{$video_id}:user:{$user_id}"
            : "save:{$video_id}:guest:{$visitor_token}";

        $within_limit = $this->rate_limiter->attempt(
            $rate_limit_key,
            self::RATE_LIMIT_MAX_ATTEMPTS,
            self::RATE_LIMIT_WINDOW_SECONDS
        );

        if (! $within_limit) {
            return new WP_REST_Response(['error' => 'Too many requests. Please slow down.'], 429);
        }

        $saved = $this->toggle_service->toggle($user_id, $visitor_token, $video_id);

        return new WP_REST_Response(
            [
                'success' => true,
                'saved'   => $saved,
            ],
            200
        );
    }
}
