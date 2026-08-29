<?php
/**
 * Confirms an R2/direct-MP4 URL is actually a reachable, video-like resource.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Video\R2;

/**
 * Confirms an R2/direct-MP4 URL is actually a reachable, video-like
 * resource — the R2 counterpart to `Tube_Core\Stream\CloudflareStreamDetailsFetcher`,
 * called synchronously at save time (ARCHITECTURE §-equivalent decision
 * for this source: there is no Cloudflare-style encoding pipeline to
 * poll later, so readiness is knowable, and decided, immediately).
 *
 * A plain HEAD request, never a GET — confirming reachability and
 * content-type needs none of the response body, and this project's own
 * "do not download the entire file" requirement applies doubly hard to
 * a check that runs on every admin save.
 */
final class R2VideoValidator
{
    /**
     * How long to wait for the HEAD request before treating the resource as unreachable.
     */
    private const TIMEOUT_SECONDS = 10;

    /**
     * Whether `$url` responds with a successful status and a
     * video-compatible `Content-Type`.
     *
     * Accepts a `video/*` Content-Type (what this project's own real R2
     * example serves) or `application/octet-stream` (a common generic
     * fallback some object-storage configurations serve for any binary
     * object, including a real video) — but nothing else, so an R2 key
     * that happens to point at an HTML error page or an unrelated file
     * type is correctly rejected rather than optimistically accepted.
     *
     * @param string $url The fully-resolved public URL to check — never logged/exposed with credentials, because
     *                     this source never has any (see class docblock).
     */
    public function is_reachable_video(string $url): bool
    {
        $response = wp_remote_head($url, ['timeout' => self::TIMEOUT_SECONDS]);

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);

        if (! is_int($code) || $code < 200 || $code >= 300) {
            return false;
        }

        $content_type_header = wp_remote_retrieve_header($response, 'content-type');
        $content_type        = is_string($content_type_header) ? strtolower($content_type_header) : '';

        return str_starts_with($content_type, 'video/') || str_starts_with($content_type, 'application/octet-stream');
    }
}
