<?php
/**
 * `GET /tube/v1/comments/{id}/replies`.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Http;

use Tube_Comments\Comments\Repositories\CommentLikeRepository;
use Tube_Comments\Comments\Repositories\CommentRepository;
use WP_REST_Request;
use WP_REST_Response;
use Tube_Comments\Support\Params;

/**
 * `GET /tube/v1/comments/{id}/replies` — one root comment's replies,
 * loaded on demand (Phase 24: "Xem 5 câu trả lời"). Public: guests can read.
 */
final class CommentRepliesController
{
    /**
     * Replies returned per page.
     */
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
        $parent_id = Params::int($request->get_param('id'));
        $offset    = max(0, Params::int($request->get_param('offset')));

        $rows = $this->comments->list_replies($parent_id, self::PAGE_SIZE, $offset);
        $next = count($rows) === self::PAGE_SIZE ? (string) ($offset + self::PAGE_SIZE) : null;

        $presenter = new CommentPresenter(new CommentLikeRepository());

        return new WP_REST_Response(
            [
                'items' => $presenter->present_many($rows, get_current_user_id()),
                'next'  => $next,
            ],
            200
        );
    }
}
