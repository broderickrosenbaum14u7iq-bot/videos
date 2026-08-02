<?php
/**
 * `POST /tube/v1/videos/{id}/watch-history`.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\WatchHistory;

use Tube_Core\Content\VideoPostType;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /tube/v1/videos/{id}/watch-history` — records the current
 * viewer's progress on a video, per ARCHITECTURE.md §12 Phase 5
 * ("watch_history table + API").
 *
 * Public (`permission_callback` is `__return_true`): both guests and
 * logged-in viewers call this for their own progress on their own
 * viewing, the same public-endpoint shape the view-recording endpoint
 * (deferred, Phase 4) was designed around. No nonce requirement — this
 * endpoint only ever writes the caller's own progress against a video
 * ID and a progress value it validates itself, never reads or exposes
 * another viewer's data, so there's no cross-user confidentiality/
 * integrity concern a nonce would be protecting against.
 */
final class WatchHistoryController
{
    /**
     * Sane upper bound for `progress_seconds` — 24 hours. Deliberately
     * not validated against the specific video's actual duration, which
     * would require an extra lookup for a value this endpoint doesn't
     * otherwise need; a generous fixed ceiling is enough to reject
     * obviously-garbage input per ARCHITECTURE.md §12 Phase 5's "never
     * trust client input."
     */
    private const MAX_PROGRESS_SECONDS = 86400;

    /**
     * Construct around the collaborators this controller delegates to.
     *
     * @param WatchHistoryRecorder $recorder      Records the progress.
     * @param VisitorToken         $visitor_token Gets/creates a guest's token.
     */
    public function __construct(
        private readonly WatchHistoryRecorder $recorder,
        private readonly VisitorToken $visitor_token
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
        // already guarantees this, but a handler shouldn't rely solely
        // on routing-layer validation for what it casts and queries with
        // (ARCHITECTURE.md §12 Phase 5 — "never trust client input").
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

        $progress_param = $request->get_param('progress_seconds');

        if (! is_numeric($progress_param)) {
            return new WP_REST_Response(['error' => 'Missing or invalid "progress_seconds".'], 400);
        }

        $progress_seconds = (int) $progress_param;

        if ($progress_seconds < 0 || $progress_seconds > self::MAX_PROGRESS_SECONDS) {
            return new WP_REST_Response(['error' => '"progress_seconds" is out of range.'], 400);
        }

        // rest_sanitize_boolean(), not a bare (bool) cast: a client
        // sending "completed=false" as a query-string/form value (rather
        // than a real JSON boolean) would otherwise be cast to true --
        // PHP treats any non-empty string, including the string "false",
        // as truthy. This is WordPress's own idiomatic helper for exactly
        // this REST-param shape; it only accepts bool|string|int, so a
        // non-scalar value (an array, missing entirely) is treated as
        // falsy directly, the same "never trust client input" posture
        // already applied to id/progress_seconds above.
        $completed_param = $request->get_param('completed');
        $completed       = (is_bool($completed_param) || is_string($completed_param) || is_int($completed_param))
            ? rest_sanitize_boolean($completed_param)
            : false;

        $current_user_id = get_current_user_id();

        if (0 !== $current_user_id) {
            $this->recorder->record($current_user_id, null, $video_id, $progress_seconds, $completed);
        } else {
            $guest_token = $this->visitor_token->get_or_create();
            $this->recorder->record(null, $guest_token, $video_id, $progress_seconds, $completed);
        }

        return new WP_REST_Response(['success' => true], 200);
    }
}
