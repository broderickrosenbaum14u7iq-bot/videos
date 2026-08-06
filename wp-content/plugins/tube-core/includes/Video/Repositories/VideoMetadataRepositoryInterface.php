<?php
/**
 * Contract for wp_tube_video_metadata data access.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Video\Repositories;

use Tube_Core\Video\CfStreamStatus;
use Tube_Core\Video\VideoMetadata;

/**
 * Contract for wp_tube_video_metadata data access, per the
 * `{Noun}Repository` convention (ARCHITECTURE.md §19.4).
 *
 * Not built in Phase 1 (which only created the table via migration) —
 * this is the first real consumer, needed by `VideoImporter`/
 * `StreamStatusUpdater` (Phase 5's import pipeline and Cloudflare Stream
 * webhook handler) and, as of `find()`, `tube-player` (Phase 6's
 * rendering layer, via a direct call from its own
 * `includes/template-tags.php` — the one WordPress/tube-core-coupled
 * boundary in that plugin, verified live rather than unit-tested).
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
     * Fetch the full stored metadata for one video.
     *
     * The read path `tube-player` (Phase 6) uses to render a video —
     * unlike `status_for()`, this returns every column a renderer needs
     * (Stream UID, duration, thumbnail offset, image overrides) in one
     * query, not one call per field.
     *
     * @param int $video_id The video post ID.
     *
     * @return VideoMetadata|null The stored metadata, or null if the video has no metadata row.
     */
    public function find(int $video_id): ?VideoMetadata;

    /**
     * Fetch the full stored metadata for several videos in one query —
     * the batch read `tube-seo`'s sitemap generator (Phase 9) uses to
     * avoid one query per video across a 3,000–10,000-video catalog.
     * `find()` stays the single-row read path (`tube-player`'s per-card
     * rendering, Phase 6/8) — a real, distinct caller, not a duplicate.
     *
     * @param int[] $video_ids The video post IDs to fetch.
     *
     * @return array<int, VideoMetadata> Keyed by video ID. A video with no metadata row is simply absent.
     */
    public function find_many(array $video_ids): array;

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

    /**
     * Update a video's custom poster/OG image overrides, per
     * ARCHITECTURE.md §8 — both are Cloudflare Images IDs, never URLs.
     * `tube-admin`'s custom-poster upload UI (Phase 10) is the only
     * writer of these two columns; a null value clears the override back
     * to the default Cloudflare Stream thumbnail.
     *
     * @param int      $video_id        The video post ID.
     * @param int|null $poster_image_id The Cloudflare Images ID to use as the poster override, or null to clear it.
     * @param int|null $og_image_id     The Cloudflare Images ID to use as the OG-image override, or null to clear it.
     */
    public function update_images(int $video_id, ?int $poster_image_id, ?int $og_image_id): void;

    /**
     * Update a video's thumbnail source-frame offset (the second within
     * the Stream asset the default poster is extracted from). Written by
     * `tube-admin`'s video metadata management screen (Phase 10).
     *
     * @param int $video_id                The video post ID.
     * @param int $thumbnail_time_seconds  The offset, in seconds, to extract the default thumbnail from.
     */
    public function update_thumbnail_time(int $video_id, int $thumbnail_time_seconds): void;
}
