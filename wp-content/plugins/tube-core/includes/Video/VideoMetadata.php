<?php
/**
 * A read-only snapshot of one video's wp_tube_video_metadata row.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Video;

/**
 * A read-only snapshot of one video's wp_tube_video_metadata row, per
 * ARCHITECTURE.md §12 Phase 6 — the shape `tube-player` (and any future
 * consumer needing more than one field at once) reads through
 * `VideoMetadataRepositoryInterface::find()`. Never carries a resolved
 * playback URL, per ARCHITECTURE.md §2.1 — only a Cloudflare Stream UID
 * or an R2 object key, depending on {@see self::$source}; the consumer
 * (tube-player) resolves either into an actual URL at render time.
 *
 * `$cf_status` is reused as a source-agnostic readiness signal for both
 * sources (added when R2 support was introduced) — for `R2Mp4` it is
 * only ever `Ready` or `Error`, set synchronously at save time from a
 * live reachability check (there is no Cloudflare-style encoding
 * pipeline/`Pending`/`Processing` state for a direct file); for
 * `CloudflareStream` it keeps its original meaning unchanged.
 */
final class VideoMetadata
{
    /**
     * Construct an immutable snapshot of one video's stored metadata.
     *
     * @param int            $video_id               The video post ID.
     * @param VideoSource    $source                 Which backend this video's bytes come from.
     * @param string|null    $cf_stream_uid          The Cloudflare Stream UID — only for `CloudflareStream` source.
     * @param string|null    $r2_object_key          The R2 object key — only for `R2Mp4` source.
     * @param CfStreamStatus $cf_status              The current readiness status (see class docblock).
     * @param int|null       $duration_seconds       The video's duration, if known.
     * @param int            $thumbnail_time_seconds Default-thumbnail extraction offset, in seconds (Stream only).
     * @param int|null       $poster_image_id        WP attachment ID overriding the default poster (ADR-0001).
     * @param int|null       $og_image_id            WP attachment ID overriding the default OG image (ADR-0001).
     */
    public function __construct(
        public readonly int $video_id,
        public readonly VideoSource $source,
        public readonly ?string $cf_stream_uid,
        public readonly ?string $r2_object_key,
        public readonly CfStreamStatus $cf_status,
        public readonly ?int $duration_seconds,
        public readonly int $thumbnail_time_seconds,
        public readonly ?int $poster_image_id,
        public readonly ?int $og_image_id
    ) {
    }
}
