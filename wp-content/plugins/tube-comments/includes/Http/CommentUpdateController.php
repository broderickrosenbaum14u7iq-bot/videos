<?php
/**
 * `POST /tube/v1/comments/{id}`.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Http;

use Tube_Comments\Comments\CommentService;
use Tube_Comments\Comments\ForbiddenException;
use Tube_Comments\Comments\Repositories\CommentLikeRepository;
use Tube_Comments\Comments\ValidationException;
use WP_REST_Request;
use WP_REST_Response;
use Tube_Comments\Support\Params;

/**
 * `POST /tube/v1/comments/{id}` — edit the requester's own comment
 * (Phase 18). Ownership is enforced inside `CommentService::update()`.
 */
final class CommentUpdateController
{
    /**
     * Construct around the service that enforces ownership and persists the edit.
     *
     * @param CommentService $service Enforces ownership and persists the edit.
     */
    public function __construct(private readonly CommentService $service)
    {
    }

    /**
     * The route callback.
     *
     * @param WP_REST_Request $request The incoming request.
     */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $comment_id = Params::int($request->get_param('id'));
        $content    = Params::string($request->get_param('content'));
        $user_id    = get_current_user_id();

        try {
            $row = $this->service->update($comment_id, $user_id, $content);
        } catch (ForbiddenException $exception) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error'   => $exception->getMessage(),
                ],
                403
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

        return new WP_REST_Response(
            [
                'success' => true,
                'comment' => $presenter->present_many([$row], $user_id)[0] ?? null,
            ],
            200
        );
    }
}
