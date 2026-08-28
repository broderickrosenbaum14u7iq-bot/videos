<?php
/**
 * Resolves and updates a member's avatar.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Profile;

use Tube_Members\Support\Params;
use WP_Error;
use WP_User;

/**
 * Resolves and updates a member's avatar, per Phase 10's priority order:
 * 1. a user-uploaded avatar (a real Media Library attachment, so
 *    responsive image sizes/CDN handling this project already relies on
 *    for other media just work);
 * 2. a Google avatar URL, recorded in usermeta at OAuth sign-in time
 *    (`GoogleOAuthController` writes {@see self::META_GOOGLE_AVATAR_URL});
 * 3. a safe generated default — an inline SVG data URI with the
 *    member's first initial on a deterministic color, never a request
 *    to Gravatar or any other third-party avatar service.
 *
 * Upload validation checks the file's real content (`getimagesize()`,
 * `wp_check_filetype_and_ext()`), not just its extension or client-
 * supplied MIME type, before ever handing it to WordPress's own media
 * pipeline (`media_handle_sideload()` — the same `wp_handle_upload()` +
 * `wp_insert_attachment()` + `wp_generate_attachment_metadata()` path
 * core's own uploader uses, so this project's existing responsive-image
 * handling applies to avatars for free).
 */
final class AvatarService
{
    /**
     * The usermeta key an uploaded avatar's attachment ID is stored under.
     */
    private const META_ATTACHMENT_ID = 'tube_members_avatar_id';

    /**
     * The usermeta key a Google-sourced avatar URL is stored under.
     * Public so `Tube_Members\OAuth\GoogleOAuthController` can write it
     * without this class needing to know anything about OAuth.
     */
    public const META_GOOGLE_AVATAR_URL = 'tube_members_google_avatar_url';

    /**
     * Maximum accepted upload size, in bytes.
     */
    private const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * Accepted MIME types => their canonical extension.
     *
     * @var array<string, string>
     */
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * This member's current avatar URL, per the priority order above.
     *
     * @param int $user_id The member account.
     */
    public function url_for(int $user_id): string
    {
        $attachment_id = Params::int(get_user_meta($user_id, self::META_ATTACHMENT_ID, true));

        if ($attachment_id > 0) {
            $url = wp_get_attachment_image_url($attachment_id, 'thumbnail');

            if (is_string($url)) {
                return $url;
            }
        }

        $google_url = get_user_meta($user_id, self::META_GOOGLE_AVATAR_URL, true);

        if (is_string($google_url) && '' !== $google_url) {
            return $google_url;
        }

        return $this->default_avatar_url($user_id);
    }

    /**
     * A safe, generated default avatar — never Gravatar.
     *
     * @param int $user_id The member account (0 for a guest).
     */
    public function default_avatar_url(int $user_id): string
    {
        $user   = get_userdata($user_id);
        $name   = $user instanceof WP_User ? $user->display_name : '?';
        $letter = '' === $name ? '?' : mb_strtoupper(mb_substr($name, 0, 1));

        $palette = ['#ef4444', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316'];
        $color   = $palette[ $user_id % count($palette) ];

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            . '<rect width="64" height="64" rx="32" fill="' . esc_attr($color) . '"/>'
            . '<text x="32" y="43" font-family="Arial, Helvetica, sans-serif" font-size="28" '
            . 'fill="#ffffff" text-anchor="middle">' . esc_html($letter) . '</text></svg>';

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data: URI encoding for an inline SVG image, not code obfuscation.
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Validate and store an uploaded avatar for $user_id, deleting the
     * previous uploaded avatar (if any) once the new one is in place.
     *
     * @param int   $user_id The member account.
     * @param array $file    One entry from `WP_REST_Request::get_file_params()` — the same
     *                       `name`/`type`/`tmp_name`/`error`/`size` shape as a raw `$_FILES` entry.
     *
     * @phpstan-param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int} $file
     *
     * @throws AvatarUploadException If the upload is missing, oversized, or not a real image.
     */
    public function upload(int $user_id, array $file): string
    {
        if (! isset($file['tmp_name'], $file['error']) || UPLOAD_ERR_OK !== $file['error']) {
            throw new AvatarUploadException('Không thể tải ảnh lên. Vui lòng thử lại.');
        }

        if (isset($file['size']) && $file['size'] > self::MAX_BYTES) {
            throw new AvatarUploadException('Ảnh đại diện phải nhỏ hơn 2MB.');
        }

        $filetype = wp_check_filetype_and_ext($file['tmp_name'], $file['name'] ?? 'avatar');
        $mime     = is_string($filetype['type']) ? $filetype['type'] : '';

        if (! isset(self::ALLOWED_MIME[ $mime ])) {
            throw new AvatarUploadException('Chỉ chấp nhận ảnh JPEG, PNG hoặc WebP.');
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- deliberately silenced: a non-image file is an expected, user-facing validation failure, not a PHP error to surface in logs.
        if (false === @getimagesize($file['tmp_name'])) {
            throw new AvatarUploadException('Tệp tải lên không phải là ảnh hợp lệ.');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $overrides = [
            'test_form' => false,
            'mimes'     => array_flip(self::ALLOWED_MIME),
        ];
        // @phpstan-ignore argument.type (the installed WordPress stub over-narrows $file_array to array<string>; a real $_FILES-shaped entry legitimately carries int values for 'error'/'size', which is exactly what media_handle_sideload() itself expects at runtime)
        $attachment_id = media_handle_sideload($file, 0, null, $overrides);

        if ($attachment_id instanceof WP_Error) {
            throw new AvatarUploadException('Không thể xử lý ảnh đại diện. Vui lòng thử ảnh khác.');
        }

        $previous_id = Params::int(get_user_meta($user_id, self::META_ATTACHMENT_ID, true));

        update_user_meta($user_id, self::META_ATTACHMENT_ID, $attachment_id);

        if ($previous_id > 0 && $previous_id !== $attachment_id) {
            wp_delete_attachment($previous_id, true);
        }

        $url = wp_get_attachment_image_url($attachment_id, 'thumbnail');

        return is_string($url) ? $url : $this->url_for($user_id);
    }
}
