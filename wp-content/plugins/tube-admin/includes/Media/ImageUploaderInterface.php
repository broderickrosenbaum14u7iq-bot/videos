<?php
/**
 * Contract for uploading a custom poster/OG image override to Cloudflare Images.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Media;

/**
 * Contract for uploading a custom poster/OG image override to Cloudflare
 * Images, per ARCHITECTURE.md §8 — the local WordPress media library is
 * never used for this (would mean millions of physical derivative files
 * on the origin server at this project's scale). Only the Cloudflare
 * Images ID is ever persisted (`wp_tube_video_metadata.poster_image_id`/
 * `og_image_id`); no URL is ever stored.
 *
 * Adopted per the interface-justification rule (ARCHITECTURE.md §19.1):
 * the real payoff is {@see \Tube_Admin\Tests\Unit\Media\Fixtures\InMemoryImageUploader},
 * the fake `Tube_Admin\Media\PosterUploadService`'s own unit tests use to
 * exercise upload/replace/error-handling logic without a live Cloudflare
 * account — the same shape `CacheInterface`/`VideoProviderInterface`
 * already established.
 */
interface ImageUploaderInterface
{
    /**
     * Upload a local temporary file to Cloudflare Images.
     *
     * @param string $file_path A local filesystem path to the uploaded file's temporary contents.
     * @param string $filename  The original filename (used for content-type inference only, never trusted).
     *
     * @return int The Cloudflare Images ID to persist. Cloudflare's own IDs are
     *             opaque UUID strings, not integers, so implementations
     *             supply a custom numeric ID at upload time to satisfy
     *             `wp_tube_video_metadata.poster_image_id`/`og_image_id`'s
     *             `BIGINT UNSIGNED` column type (ARCHITECTURE.md §2.1) —
     *             see `CloudflareImagesUploader`'s own docblock.
     *
     * @throws ImageUploadException If the upload fails for any reason (network, bad response, malformed body).
     */
    public function upload(string $file_path, string $filename): int;

    /**
     * Delete a previously-uploaded image from Cloudflare Images — called
     * when a poster/OG override is replaced or cleared, so a superseded
     * upload doesn't linger as an orphaned, still-billable asset.
     *
     * Deliberately best-effort at the call site (see
     * `PosterUploadService`): a delete failure for an already-superseded
     * image is logged, not allowed to block the new image from taking
     * effect, since the new image is already live in
     * `wp_tube_video_metadata` by the time this runs.
     *
     * @param int $image_id The Cloudflare Images ID to delete.
     *
     * @throws ImageUploadException If the delete request fails for any reason.
     */
    public function delete(int $image_id): void;
}
