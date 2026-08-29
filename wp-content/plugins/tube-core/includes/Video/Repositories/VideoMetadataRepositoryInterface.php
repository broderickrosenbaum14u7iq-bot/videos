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
use Tube_Core\Video\VideoSource;

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
     * Create the metadata row for a newly-created R2/direct-MP4 video —
     * the R2 counterpart to {@see self::create()}. `$status` is always
     * `Ready` or `Error` (never `Pending`/`Processing`, which describe a
     * Cloudflare Stream encoding pipeline this source doesn't have),
     * decided synchronously by the caller from a live reachability check
     * against the resolved public URL before this is called.
     *
     * @param int            $video_id      The video post ID.
     * @param string         $r2_object_key The canonical (decoded) R2 object key — never a playback URL.
     * @param CfStreamStatus $status        `Ready` if the object was confirmed reachable/video-like, `Error` otherwise.
     */
    public function create_r2(int $video_id, string $r2_object_key, CfStreamStatus $status): void;

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
     * Find the video an R2 object key belongs to — the R2 counterpart to
     * {@see self::find_video_id_by_stream_uid()}, used the same way for
     * duplicate-object detection before {@see self::create_r2()}/{@see self::update_r2_object_key()}.
     *
     * @param string $r2_object_key The R2 object key to look up.
     *
     * @return int|null The video post ID, or null if no metadata row references this object key.
     */
    public function find_video_id_by_r2_object_key(string $r2_object_key): ?int;

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
     * ARCHITECTURE.md §8 (as revised by ADR-0001, and its 2026-08-25
     * addendum) — both are WordPress Media Library attachment IDs, never
     * URLs and never Cloudflare Images IDs. `Tube_Admin\Video\PosterImageMetaBox`
     * is the only writer of `poster_image_id`; `tube-admin`'s
     * `VideoDetailsScreen` remains the only writer of `og_image_id`. A
     * null value clears the override to "no image" — there is no
     * Cloudflare Stream thumbnail fallback to clear back to anymore.
     *
     * @param int      $video_id        The video post ID.
     * @param int|null $poster_image_id The WordPress attachment ID to use as the poster override, or null to clear.
     * @param int|null $og_image_id     The WordPress attachment ID to use as the OG-image override, or null to clear.
     */
    public function update_images(int $video_id, ?int $poster_image_id, ?int $og_image_id): void;

    /**
     * Update a video's Cloudflare Stream UID.
     *
     * Added alongside ADR-0001: previously `cf_stream_uid` was write-once
     * at {@see self::create()} time (only ever set by the WP-CLI import
     * pipeline) with no update path. `tube-admin`'s video edit screen now
     * lets an administrator manually enter/correct the UID for an
     * existing video, so a genuine update path is needed. The caller is
     * responsible for uniqueness validation before calling this (see
     * {@see self::find_video_id_by_stream_uid()}) — the underlying
     * `cf_stream_uid_idx` UNIQUE KEY (`Migration001CreateVideoMetadataTable`)
     * remains the hard backstop against a duplicate actually persisting,
     * the same division of responsibility `VideoImporter::import()`
     * already established for the create-time case.
     *
     * @param int    $video_id      The video post ID.
     * @param string $cf_stream_uid The new Cloudflare Stream UID.
     */
    public function update_stream_uid(int $video_id, string $cf_stream_uid): void;

    /**
     * Update a video's R2 object key — the R2 counterpart to
     * {@see self::update_stream_uid()}, same division of responsibility
     * (caller validates uniqueness first via {@see self::find_video_id_by_r2_object_key()};
     * the `r2_object_key_idx` UNIQUE KEY is the hard backstop).
     *
     * @param int    $video_id      The video post ID.
     * @param string $r2_object_key The new R2 object key.
     */
    public function update_r2_object_key(int $video_id, string $r2_object_key): void;

    /**
     * Update a video's thumbnail source-frame offset (the second within
     * the Stream asset the default poster is extracted from). Written by
     * `tube-admin`'s video metadata management screen (Phase 10).
     *
     * @param int $video_id                The video post ID.
     * @param int $thumbnail_time_seconds  The offset, in seconds, to extract the default thumbnail from.
     */
    public function update_thumbnail_time(int $video_id, int $thumbnail_time_seconds): void;

    /**
     * List every video's ID and Cloudflare Stream UID, one page at a
     * time, ordered by video_id — the batch-resync read path
     * `Tube_Core\CLI\StreamCommand` walks the whole catalog with, without
     * loading it all into memory at once (ARCHITECTURE.md §10's
     * 500,000+-video scale target). The same `$limit`/`$offset`
     * do-while-until-a-short-page pagination shape
     * `Tube_Search\CLI\IndexCommand::rebuild()` already established.
     *
     * @param int $limit  How many rows to return.
     * @param int $offset How many rows to skip.
     *
     * @return list<array{video_id: int, cf_stream_uid: string}>
     */
    public function all_stream_uids(int $limit, int $offset): array;
}
