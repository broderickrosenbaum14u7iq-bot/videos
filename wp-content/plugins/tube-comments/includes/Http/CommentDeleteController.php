<?php
/**
 * `POST /tube/v1/comments/{id}/delete`.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Http;

use Tube_Comments\Comments\CommentService;
use Tube_Comments\Comments\ForbiddenException;
use Tube_Comments\Comments\ValidationException;
use WP_REST_Request;
use WP_REST_Response;
use Tube_Comments\Support\Params;

/**
 * `POST /tube/v1/comments/{id}/delete` — soft-delete the requester's own
 * comment (Phase 18). A dedicated `/delete` sub-route rather than the
 * HTTP `DELETE` verb: this project's shared `tube/v1` REST routes
 * elsewhere (webhooks, view/like/save toggles) are all POST-only, so
 * this stays consistent rather than introducing the only DELETE verb in
 * the namespace.
 */
final class CommentDeleteController
{
    /**
     * Construct around the service that enforces ownership and soft-deletes.
     *
     * @param CommentService $service Enforces ownership and soft-deletes.
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

        try {
            $this->service->delete($comment_id, get_current_user_id());
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
                404
            );
        }

        return new WP_REST_Response(['success' => true], 200);
    }
}
