<?php
/**
 * ImageUploaderInterface implementation backed by the real Cloudflare Images API.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Media;

use WP_Error;

/**
 * ImageUploaderInterface implementation backed by the real Cloudflare
 * Images v1 API (`POST/DELETE /accounts/{account_id}/images/v1`), per
 * ARCHITECTURE.md §8. WordPress-coupled throughout (`wp_remote_post()`/
 * `wp_remote_request()`) and integration-tested only, the same split
 * `Tube_Seo\Head\SeoHead` already established — {@see self::build_multipart_body()}
 * is the one piece with zero WordPress dependency and is unit-tested
 * directly.
 *
 * Cloudflare's own image IDs are opaque UUID strings, not integers, but
 * `wp_tube_video_metadata.poster_image_id`/`og_image_id` are `BIGINT
 * UNSIGNED` (ARCHITECTURE.md §2.1) — changing that column type would be
 * an ARCHITECTURE.md schema change requiring the full ADR process
 * (`DEVELOPMENT_RULES.md` §8), not something to do silently mid-phase.
 * Cloudflare Images' upload API accepts an arbitrary caller-supplied
 * `id` string instead of generating a UUID; this class supplies a random
 * 63-bit unsigned integer as that custom ID, so the column's existing
 * type stays meaningful without any schema change.
 *
 * Not live-network-verified in this project's staging environment: the
 * account ID/API token in `.env.example` are placeholders (no real
 * Cloudflare Images account exists to test against), the same documented
 * limit `CLOUDFLARE_STREAM_WEBHOOK_SECRET`'s "fail-closed if left empty"
 * note already applies to Phase 5's webhook path. See `PHASE-10.md`.
 */
final class CloudflareImagesUploader implements ImageUploaderInterface
{
    /**
     * Cloudflare's API base URL.
     */
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    /**
     * Construct the uploader with its Cloudflare credentials.
     *
     * @param string $account_id The Cloudflare account ID (not the delivery-URL "account hash" tube-player uses).
     * @param string $api_token  A Cloudflare API token scoped to Images:Edit.
     */
    public function __construct(
        private readonly string $account_id,
        private readonly string $api_token
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param string $file_path A local filesystem path to the uploaded file's temporary contents.
     * @param string $filename  The original filename.
     *
     * @throws ImageUploadException If the upload fails for any reason.
     */
    public function upload(string $file_path, string $filename): int
    {
        $this->guard_configured();

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local temp file already validated and moved by PosterUploadService, not a remote URL; WP_Filesystem is unavailable this early and unnecessary for a plugin's own already-owned temp file.
        $contents = file_get_contents($file_path);

        if (false === $contents) {
            throw new ImageUploadException('Could not read the uploaded file.');
        }

        $custom_id = (string) random_int(1, PHP_INT_MAX);
        $boundary  = wp_generate_password(24, false);
        $body      = self::build_multipart_body($boundary, $contents, $filename, $custom_id);

        $response = wp_remote_post(
            self::API_BASE . "/accounts/{$this->account_id}/images/v1",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_token,
                    'Content-Type'  => "multipart/form-data; boundary={$boundary}",
                ],
                'body'    => $body,
                'timeout' => 30,
            ]
        );

        $this->guard_success($response, 'upload');

        return (int) $custom_id;
    }

    /**
     * {@inheritDoc}
     *
     * @param int $image_id The Cloudflare Images ID to delete.
     *
     * @throws ImageUploadException If the delete request fails for any reason.
     */
    public function delete(int $image_id): void
    {
        $this->guard_configured();

        $response = wp_remote_request(
            self::API_BASE . "/accounts/{$this->account_id}/images/v1/{$image_id}",
            [
                'method'  => 'DELETE',
                'headers' => ['Authorization' => 'Bearer ' . $this->api_token],
                'timeout' => 15,
            ]
        );

        $this->guard_success($response, 'delete');
    }

    /**
     * Build a Cloudflare Images v1 upload request body as
     * `multipart/form-data` — pure string construction, no WordPress
     * dependency, unit-tested directly (unlike the rest of this class).
     *
     * @param string $boundary      The multipart boundary token (caller-generated, random per request).
     * @param string $file_contents The raw file bytes to upload.
     * @param string $filename      The original filename (sanitized to a safe character set for the header only).
     * @param string $custom_id     The custom Cloudflare Images ID to request.
     */
    public static function build_multipart_body(
        string $boundary,
        string $file_contents,
        string $filename,
        string $custom_id
    ): string {
        $safe_filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        $safe_filename = null === $safe_filename || '' === $safe_filename ? 'upload' : $safe_filename;

        $id_part = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"id\"\r\n\r\n"
            . "{$custom_id}\r\n";

        $file_part = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"{$safe_filename}\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . $file_contents . "\r\n";

        $closing = "--{$boundary}--\r\n";

        return $id_part . $file_part . $closing;
    }

    /**
     * Reject a call before ever reaching the network if this instance
     * wasn't given real credentials — fail-closed, the same posture
     * `Tube_Core\Stream\WebhookSignatureVerifier` already established for
     * an empty `CLOUDFLARE_STREAM_WEBHOOK_SECRET`.
     *
     * @throws ImageUploadException If either credential is empty.
     */
    private function guard_configured(): void
    {
        if ('' === $this->account_id || '' === $this->api_token) {
            throw new ImageUploadException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception message, never rendered as HTML output; the caller decides how to surface this safely.
                'Cloudflare Images is not configured (missing account ID or API token). See the Settings page.'
            );
        }
    }

    /**
     * Validate an HTTP response as a successful Cloudflare API call —
     * network failure, non-2xx status, and a body that doesn't report
     * `"success": true` are all treated as failure.
     *
     * @param array<string, mixed>|WP_Error $response The raw wp_remote_post()/wp_remote_request() result.
     * @param string                        $action   A short label ("upload"/"delete") for the exception message.
     *
     * @throws ImageUploadException If the response indicates failure.
     */
    private function guard_success(array|WP_Error $response, string $action): void
    {
        if (is_wp_error($response)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception message, never rendered as HTML output; $action is always one of this class's own literal call-site strings ("upload"/"delete"), never external input.
            throw new ImageUploadException("Cloudflare Images {$action} request failed: network error.");
        }

        $code = wp_remote_retrieve_response_code($response);

        if (! is_int($code) || $code < 200 || $code >= 300) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- see the comment above; $action is never external input.
            throw new ImageUploadException("Cloudflare Images {$action} request failed with a bad HTTP status.");
        }

        $body    = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || true !== ($decoded['success'] ?? null)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- see the comment above; $action is never external input.
            throw new ImageUploadException("Cloudflare Images {$action} request did not report success.");
        }
    }
}
