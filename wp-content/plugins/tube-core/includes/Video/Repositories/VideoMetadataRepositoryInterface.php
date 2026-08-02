<?php
/**
 * Contract for wp_tube_video_metadata data access.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Video\Repositories;

use Tube_Core\Video\CfStreamStatus;

/**
 * Contract for wp_tube_video_metadata data access, per the
 * `{Noun}Repository` convention (ARCHITECTURE.md §19.4).
 *
 * Not built in Phase 1 (which only created the table via migration) —
 * this is the first real consumer, needed by both `VideoImporter`
 * (Phase 5's import pipeline) and `StreamStatusUpdater` (Phase 5's
 * Cloudflare Stream webhook handler).
 *
 * Adopted per the interface-justification rule (§19.1): the real payoff
 * is a test fake `VideoImporter`, `BatchProcessor`, and
 * `StreamStatusUpdater` are each actually unit-tested against, without a
 * live database.
 */
interface VideoMetadataRepositoryInterface
{
    /**
     * Create the metadata row for a newly-created video.
     *
     * @param int            $video_id      The video post ID.
     * @param string         $cf_stream_uid The Cloudflare Stream UID — never a playback URL (ARCHITECTURE.md §2.1).
     * @param CfStreamStatus $status        The initial encoding status.
     */
    public function create(int $video_id, string $cf_stream_uid, CfStreamStatus $status): void;

    /**
     * Find the video a Cloudflare Stream UID belongs to.
     *
     * Used two ways: `VideoImporter` calls this for duplicate detection
     * (a UID already known means this video was already imported);
     * `StreamStatusUpdater` calls this to map an incoming webhook's UID
     * to the video it should update.
     *
     * @param string $cf_stream_uid The Cloudflare Stream UID to look up.
     *
     * @return int|null The video post ID, or null if no metadata row references this UID.
     */
    public function find_video_id_by_stream_uid(string $cf_stream_uid): ?int;

    /**
     * The current encoding status for a video.
     *
     * `StreamStatusUpdater` reads this before writing a new one, so it
     * can tell whether an incoming webhook actually changes anything —
     * the mechanism behind safely handling duplicate/redelivered webhook
     * events (ARCHITECTURE.md §12 Phase 5).
     *
     * @param int $video_id The video post ID.
     *
     * @return CfStreamStatus|null The current status, or null if the video has no metadata row.
     */
    public function status_for(int $video_id): ?CfStreamStatus;

    /**
     * Update a video's encoding status and, when known, its duration.
     *
     * @param int            $video_id         The video post ID.
     * @param CfStreamStatus $status           The new encoding status.
     * @param int|null       $duration_seconds The video's duration, if the caller knows it; left unchanged if null.
     */
    public function update_status(int $video_id, CfStreamStatus $status, ?int $duration_seconds): void;
}
