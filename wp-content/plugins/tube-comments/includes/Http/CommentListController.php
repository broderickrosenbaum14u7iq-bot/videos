<?php
/**
 * `GET /tube/v1/videos/{id}/comments`.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Http;

use Tube_Comments\Comments\AntiSpam\SpamPolicy;
use Tube_Comments\Comments\Repositories\CommentLikeRepository;
use Tube_Comments\Comments\Repositories\CommentRepository;
use Tube_Comments\Comments\Repositories\CommentRootLockRepository;
use WP_REST_Request;
use WP_REST_Response;
use Tube_Comments\Support\Params;

/**
 * `GET /tube/v1/videos/{id}/comments` — a video's root comments, per
 * Phase 23 (sort) and Phase 24 (pagination). Public: guests can read
 * (Phase 12).
 *
 * Also carries the current viewer's root-comment anti-spam status
 * (`viewer_root_comment_status`, logged-in visitors only) so the
 * frontend composer's blocked/available state always comes from a fresh
 * server read on every page load — never from `localStorage`, which
 * would not survive a device change and would not reflect a block
 * created from another device/session.
 *
 * 2026-08-27 watch-page comment collapse: `limit` is now a request
 * param instead of one fixed page size — the frontend sends
 * `limit=3` for the very first load (so the initial page render never
 * needs more than 3 root comments' worth of rows) and `limit=5` for
 * every subsequent "Xem thêm bình luận" click, per that task's own
 * "3 initially, 5 per click" spec. The underlying query/cursor
 * mechanism ({@see CommentRepository::list_root_recent()}/
 * {@see CommentRepository::list_root_popular()}) is completely
 * unchanged — reused exactly as it already existed, just called with a
 * smaller `$limit` than the old fixed 20.
 */
final class CommentListController
{
    /**
     * The root-comment limit when the request supplies none/an invalid
     * one — matches the very first page's own "3 roots" requirement, so
     * a client that forgets the param still gets sane behavior rather
     * than the old, much taller, fixed 20.
     */
    private const DEFAULT_LIMIT = 3;

    /**
     * A hard ceiling on `limit`, regardless of what the request asks
     * for — never removed entirely: an unbounded client-supplied limit
     * would let a single request re-introduce the exact "fetch
     * everything at once" cost this whole feature exists to avoid.
     */
    private const MAX_LIMIT = 20;

    /**
     * Construct around the repository this controller reads from.
     *
     * @param CommentRepository $comments The comment rows themselves.
     */
    public function __construct(private readonly CommentRepository $comments)
    {
    }

    /**
     * The route callback.
     *
     * @param WP_REST_Request $request The incoming request.
     */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $video_id      = Params::int($request->get_param('id'));
        $sort          = 'popular' === $request->get_param('sort') ? 'popular' : 'recent';
        $after         = $request->get_param('after');
        $cursor        = is_numeric($after) ? (int) $after : 0;
        $requested_max = Params::int($request->get_param('limit'));
        $limit         = $requested_max > 0 ? min($requested_max, self::MAX_LIMIT) : self::DEFAULT_LIMIT;

        // Fetch one EXTRA row beyond $limit purely to answer "is there
        // more" without a second COUNT(*) query -- `count($rows) ===
        // $limit` alone can't distinguish "exactly $limit rows total"
        // from "more than $limit rows total" (found live during QA,
        // 2026-08-27: with exactly 3 root comments and limit=3, the old
        // check showed "Xem thêm" anyway, since the returned count
        // happened to equal the limit either way). The extra row, when
        // present, is trimmed below and never reaches the response.
        $fetch_limit = $limit + 1;

        if ('popular' === $sort) {
            $rows = $this->comments->list_root_popular($video_id, $fetch_limit, $cursor);
        } else {
            $rows = $this->comments->list_root_recent($video_id, $fetch_limit, $cursor > 0 ? $cursor : null);
        }

        $has_more = count($rows) > $limit;

        if ($has_more) {
            $rows = array_slice($rows, 0, $limit);
        }

        if ('popular' === $sort) {
            $next = $has_more ? (string) ($cursor + $limit) : null;
        } else {
            $last = [] !== $rows ? end($rows) : null;
            $next = ($has_more && null !== $last) ? (string) Params::int($last['id']) : null;
        }

        $presenter      = new CommentPresenter(new CommentLikeRepository());
        $viewer_user_id = get_current_user_id();

        $viewer_root_comment_status = null;

        if ($viewer_user_id > 0) {
            $available_at               = (new CommentRootLockRepository())->available_at(
                $viewer_user_id,
                $video_id,
                SpamPolicy::ROOT_COMMENT_WINDOW_SECONDS
            );
            $viewer_root_comment_status = [
                'blocked'      => null !== $available_at,
                'available_at' => $available_at,
            ];
        }

        return new WP_REST_Response(
            [
                'items'                      => $presenter->present_many($rows, $viewer_user_id),
                'next'                       => $next,
                'viewer_root_comment_status' => $viewer_root_comment_status,
            ],
            200
        );
    }
}
