<?php
/**
 * `POST /tube/v1/videos/{id}/view`.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Views;

use Tube_Core\Content\VideoPostType;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /tube/v1/videos/{id}/view` — records one legitimate view for a
 * video. This is the endpoint `Tube_Core\WatchHistory\WatchHistoryController`'s
 * own docblock already referred to as "the view-recording endpoint
 * (deferred, Phase 4)" — that deferral, never followed up on, was the
 * root cause real view counts stayed at 0 despite real videos being
 * watched: `Tube_Core\Plugin::view_recorder()` (the buffer-and-announce
 * logic) and everything downstream of it (`views:flush`, `stats:rollup`,
 * the search-index/cache sync subscribers) were all real and correctly
 * wired to each other, but nothing ever called `view_recorder()->record()`
 * — this controller is that missing call site.
 *
 * Public (`permission_callback` is `__return_true`) — the same reasoning
 * `Tube_Core\WatchHistory\WatchHistoryController` already documents for
 * its own public endpoint applies here too: any visitor, including
 * guests, can genuinely start watching a video.
 *
 * **What counts as one legitimate view**: called once by
 * `tube-player`'s own click-to-load activation
 * (`assets/js/tube-player.js`'s `activate()`), the first time a visitor
 * actually clicks play — not on page load (far too noisy: every crawler
 * hit, every refresh, every visit that never watches anything), and not
 * on the Cloudflare Stream iframe's own internal HLS manifest/segment
 * requests (which go directly to Cloudflare's delivery domain and never
 * reach this server at all — there is nothing here that could count
 * them even if that were desired).
 *
 * **Every intentional play action counts** (2026-08-25 policy, reversing
 * this endpoint's original 30-minute per-viewer dedup window): the same
 * visitor watching the same video five separate times in one sitting is
 * five real views, not one — this project's own explicit "views" concept
 * is a raw play-action counter, not a unique-viewer/session metric.
 * There is deliberately **no server-side dedup by IP, cookie, visitor
 * token, or logged-in user** here anymore — see
 * `assets/js/tube-player.js`'s own `activate()` for the one guard that
 * does still apply: it already refuses to run twice for the same player
 * *instance* (`data-tube-player-active`), which is what stops a single
 * physical click from ever producing more than one request to this
 * endpoint in the first place. That guard is synchronous JavaScript with
 * no `await`/yield point before the attribute is set, so two "simultaneous"
 * clicks on the same button are still handled strictly one after another
 * by the single-threaded event loop — the second one always sees the
 * attribute the first one just set. A second, independent
 * request-level idempotency layer here would be guarding against a
 * failure mode (`fetch()` silently double-sending the same POST) that
 * doesn't exist for a plain, non-retried `fetch()` call, so none is
 * added — see this class's own git history for the removed
 * `ViewDeduplicatorInterface`/`RedisViewDeduplicator` if a future,
 * genuinely-observed duplicate-request problem needs one.
 */
final class ViewController
{
    /**
     * Construct around the collaborator this controller delegates to.
     *
     * @param ViewRecorder $view_recorder Records the view.
     */
    public function __construct(private readonly ViewRecorder $view_recorder)
    {
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
        // on routing-layer validation for what it casts and queries
        // with — the same posture WatchHistoryController's own handle()
        // documents and applies to this exact check.
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

        $this->view_recorder->record($video_id);

        return new WP_REST_Response(['success' => true], 200);
    }
}
