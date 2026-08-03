<?php
/**
 * Contract for turning a Cloudflare Stream UID into playback/thumbnail URLs.
 *
 * @package Tube_Player
 */

declare(strict_types=1);

namespace Tube_Player\Video;

/**
 * Contract for turning a Cloudflare Stream UID into playback/thumbnail
 * URLs, per ARCHITECTURE.md §19.5's frozen decision to adopt this
 * interface. Never persists a URL anywhere — every call constructs one
 * fresh, at render time, from the stored UID (ARCHITECTURE.md §2.1/§8).
 *
 * Adopted per the interface-justification rule (§19.1): the real payoff
 * is a test fake `PlayerRenderPlanner`/the HTML renderers are unit-tested
 * against, without ever calling `CloudflareStreamProvider`'s real
 * signing/URL-construction logic — and, per `ARCHITECTURE_FREEZE.md`'s
 * Known Tradeoffs, this is also the concrete boundary that mitigates
 * (not eliminates) Cloudflare Stream vendor lock-in on the video path: a
 * different implementation of this interface is what a future provider
 * swap would mean, with no caller changes required.
 */
interface VideoProviderInterface
{
    /**
     * The click-to-load iframe embed URL for a video.
     *
     * @param string $cf_stream_uid The Cloudflare Stream UID.
     *
     * @return string The embed URL — signed (a short-lived token in place of the UID) or unsigned, per configuration.
     */
    public function embed_url(string $cf_stream_uid): string;

    /**
     * The thumbnail image URL for a video at a given offset and size.
     *
     * @param string $cf_stream_uid          The Cloudflare Stream UID.
     * @param int    $thumbnail_time_seconds Offset into the video to extract the thumbnail frame from.
     * @param int    $width                  Requested pixel width.
     * @param int    $height                 Requested pixel height.
     *
     * @return string The thumbnail URL — signed or unsigned, per configuration.
     */
    public function thumbnail_url(string $cf_stream_uid, int $thumbnail_time_seconds, int $width, int $height): string;
}
