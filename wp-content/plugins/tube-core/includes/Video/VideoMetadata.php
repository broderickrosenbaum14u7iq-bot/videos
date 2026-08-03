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
 * `VideoMetadataRepositoryInterface::find()`. Never carries a playback
 * URL, per ARCHITECTURE.md §2.1 — only the Cloudflare Stream UID.
 */
final class VideoMetadata
{
    /**
     * Construct an immutable snapshot of one video's stored metadata.
     *
     * @param int            $video_id               The video post ID.
     * @param string         $cf_stream_uid          The Cloudflare Stream UID.
     * @param CfStreamStatus $cf_status              The current encoding status.
     * @param int|null       $duration_seconds       The video's duration, if known.
     * @param int            $thumbnail_time_seconds Default-thumbnail extraction offset, in seconds.
     * @param int|null       $poster_image_id        Cloudflare Images ID overriding the default poster, if set.
     * @param int|null       $og_image_id            Cloudflare Images ID overriding the default OG image, if set.
     */
    public function __construct(
        public readonly int $video_id,
        public readonly string $cf_stream_uid,
        public readonly CfStreamStatus $cf_status,
        public readonly ?int $duration_seconds,
        public readonly int $thumbnail_time_seconds,
        public readonly ?int $poster_image_id,
        public readonly ?int $og_image_id
    ) {
    }
}
