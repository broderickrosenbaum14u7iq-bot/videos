<?php
/**
 * `GET /tube/v1/comments/mine`.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Http;

use Tube_Comments\Comments\Repositories\CommentLikeRepository;
use Tube_Comments\Comments\Repositories\CommentRepository;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use Tube_Comments\Support\Params;

/**
 * `GET /tube/v1/comments/mine` — the requester's own comments, any
 * status, for the frontend account page's "Bình luận của tôi" (Phase 9).
 */
final class CommentMineController
{
    private const PAGE_SIZE = 20;

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
        $user_id = get_current_user_id();
        $offset  = max(0, Params::int($request->get_param('offset')));

        $rows = $this->comments->list_mine($user_id, self::PAGE_SIZE, $offset);
        $next = count($rows) === self::PAGE_SIZE ? (string) ($offset + self::PAGE_SIZE) : null;

        $presenter = new CommentPresenter(new CommentLikeRepository());

        $items = $presenter->present_many($rows, $user_id);

        // Attach each comment's video permalink/title, so the account
        // page can link "Bình luận của tôi" entries back to their video
        // — CommentPresenter has no notion of videos, only comment rows.
        foreach ($items as $index => $item) {
            $post = get_post(Params::int($rows[ $index ]['video_id']));

            $items[ $index ]['video'] = $post instanceof WP_Post
                ? [
                    'id'        => $post->ID,
                    'title'     => $post->post_title,
                    'permalink' => get_permalink($post),
                ]
                : null;
        }

        return new WP_REST_Response(
            [
                'items' => $items,
                'next'  => $next,
            ],
            200
        );
    }
}
