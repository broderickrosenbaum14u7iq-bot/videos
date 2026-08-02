<?php
/**
 * Turns one import queue item's payload into a video.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Import;

use InvalidArgumentException;
use RuntimeException;
use Tube_Core\Content\CategoryTaxonomy;
use Tube_Core\Content\TagTaxonomy;
use Tube_Core\Content\VideoPostType;
use Tube_Core\Video\CfStreamStatus;
use Tube_Core\Video\Repositories\VideoMetadataRepositoryInterface;
use WP_Error;

/**
 * Turns one import queue item's payload into a video, per
 * ARCHITECTURE.md §12 Phase 5.
 *
 * Expected payload shape:
 *
 * ```
 * {
 *   "title": "string, required",
 *   "cf_stream_uid": "string, required — the Cloudflare Stream UID",
 *   "category_slugs": ["string", ...],  // optional, existing video_category term slugs
 *   "tag_slugs": ["string", ...]        // optional, existing video_tag term slugs
 * }
 * ```
 *
 * Deliberately does not accept actor/studio assignment — those are
 * dedicated-table relationships (ARCHITECTURE.md §14) with no repository
 * built yet (CRUD for them is tube-admin's, Phase 10); adding that here
 * would be building against a layer that doesn't exist.
 *
 * A video is always created `draft`, never `publish` — Cloudflare Stream
 * hasn't confirmed the upload is actually playable yet at import time;
 * `StreamStatusUpdater` (the webhook handler) is what publishes it, once
 * Cloudflare reports `ready`. This is a considered behavior, not an
 * accident: publishing before the video can play would be a genuine
 * user-facing defect.
 *
 * **Duplicate detection (content level)**: if `cf_stream_uid` already
 * belongs to an existing video, that video's ID is returned as-is — a
 * successful, idempotent no-op, not an error. Re-importing already-known
 * content (e.g. the same item queued twice under different source keys,
 * or a queue item retried after partial completion) must never create a
 * second video for the same Cloudflare asset.
 */
final class VideoImporter implements VideoImporterInterface
{
    /**
     * Construct around the repository imported videos' metadata is written to.
     *
     * @param VideoMetadataRepositoryInterface $metadata_repository Where imported videos' metadata is written.
     */
    public function __construct(private readonly VideoMetadataRepositoryInterface $metadata_repository)
    {
    }

    /**
     * Import one video from a queue item's payload.
     *
     * @param array<string, mixed> $payload The queue item's payload — see
     *                                       this class's own docblock for
     *                                       the expected shape.
     *
     * @return int The video post ID — either newly created, or the
     *             existing one if `cf_stream_uid` was already imported.
     *
     * @throws InvalidArgumentException|RuntimeException If the payload is
     *         missing a required field, or if creating the video post fails.
     */
    public function import(array $payload): int
    {
        $title         = $this->require_non_empty_string($payload, 'title');
        $cf_stream_uid = $this->require_non_empty_string($payload, 'cf_stream_uid');

        $existing_video_id = $this->metadata_repository->find_video_id_by_stream_uid($cf_stream_uid);

        if (null !== $existing_video_id) {
            return $existing_video_id;
        }

        $post_id = wp_insert_post(
            [
                'post_type'   => VideoPostType::POST_TYPE,
                'post_title'  => $title,
                'post_status' => 'draft',
            ],
            true
        );

        if ($post_id instanceof WP_Error) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception message, never rendered as HTML output; caught by BatchProcessor and stored as plain text in wp_tube_import_queue.last_error.
            throw new RuntimeException('wp_insert_post() failed: ' . $post_id->get_error_message());
        }

        $video_id = (int) $post_id;

        $this->metadata_repository->create($video_id, $cf_stream_uid, CfStreamStatus::Pending);

        $this->assign_terms($video_id, $payload, 'category_slugs', CategoryTaxonomy::TAXONOMY);
        $this->assign_terms($video_id, $payload, 'tag_slugs', TagTaxonomy::TAXONOMY);

        return $video_id;
    }

    /**
     * Assign existing taxonomy terms by slug, if the payload lists any.
     *
     * Unknown slugs are silently skipped, not a failure — a typo'd or
     * not-yet-created category in bulk import data shouldn't fail the
     * video's creation over non-essential classification.
     *
     * @param int                  $video_id    The video to assign terms to.
     * @param array<string, mixed> $payload     The queue item's payload.
     * @param string               $payload_key Which optional payload field
     *                                          holds the slugs
     *                                          (`category_slugs` or `tag_slugs`).
     * @param string               $taxonomy    The taxonomy to assign terms in.
     */
    private function assign_terms(int $video_id, array $payload, string $payload_key, string $taxonomy): void
    {
        if (! isset($payload[ $payload_key ]) || ! is_array($payload[ $payload_key ])) {
            return;
        }

        $slugs = array_values(array_filter($payload[ $payload_key ], 'is_string'));

        if ([] === $slugs) {
            return;
        }

        wp_set_object_terms($video_id, $slugs, $taxonomy);
    }

    /**
     * Extract a required, non-empty string field from a payload.
     *
     * @param array<string, mixed> $payload The payload to read from.
     * @param string               $key     The required field's key.
     *
     * @throws InvalidArgumentException If the field is missing, not a string, or empty after trimming.
     */
    private function require_non_empty_string(array $payload, string $key): string
    {
        if (! isset($payload[ $key ]) || ! is_string($payload[ $key ]) || '' === trim($payload[ $key ])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception message, never rendered as HTML output; caught by BatchProcessor and stored as plain text in wp_tube_import_queue.last_error.
            throw new InvalidArgumentException("Import payload is missing a non-empty \"{$key}\".");
        }

        return $payload[ $key ];
    }
}
