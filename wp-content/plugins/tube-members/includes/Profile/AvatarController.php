<?php
/**
 * `POST /tube/v1/members/me/avatar`.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Profile;

use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /tube/v1/members/me/avatar` — gated by `is_user_logged_in()` at
 * route registration, covered by core's own cookie-nonce enforcement.
 */
final class AvatarController
{
    /**
     * Construct around the service that validates and stores the upload.
     *
     * @param AvatarService $service Validates and stores the upload.
     */
    public function __construct(private readonly AvatarService $service)
    {
    }

    /**
     * The route callback.
     *
     * @param WP_REST_Request $request The incoming multipart request.
     */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $files = $request->get_file_params();
        $file  = $files['avatar'] ?? null;

        if (! is_array($file)) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error'   => 'Vui lòng chọn một ảnh.',
                ],
                400
            );
        }

        /** @var array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int} $file */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        try {
            $avatar_url = $this->service->upload(get_current_user_id(), $file);
        } catch (AvatarUploadException $exception) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error'   => $exception->getMessage(),
                ],
                422
            );
        }

        return new WP_REST_Response(
            [
                'success'    => true,
                'avatar_url' => $avatar_url,
            ],
            200
        );
    }
}
