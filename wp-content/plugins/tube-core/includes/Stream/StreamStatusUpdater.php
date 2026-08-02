<?php
/**
 * Applies a validated Cloudflare Stream status update to a video.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Stream;

use InvalidArgumentException;
use Tube_Core\Events\Dispatcher;
use Tube_Core\Events\EventCatalog;
use Tube_Core\Video\CfStreamStatus;
use Tube_Core\Video\Repositories\VideoMetadataRepositoryInterface;
use WP_Error;
use WP_Post;

/**
 * Applies a validated Cloudflare Stream status update to a video — the
 * pure logic behind the webhook handler (ARCHITECTURE.md §12 Phase 5).
 * HTTP concerns (signature verification, request parsing) live in
 * `WebhookController`; this class only ever sees an already-validated
 * UID/status/duration, the same "WordPress-coupled boundary vs.
 * testable core" split this project uses throughout.
 *
 * **Duplicate/redelivered webhook events are safe by construction**: a
 * webhook reporting a status the video already has, with no new
 * duration information, changes nothing in the database and dispatches
 * nothing — not because of a separate event-ID dedup log, but because
 * the update is a plain compare-and-write against the video's current
 * state, which is naturally idempotent for exactly this reason. A
 * dedicated dedup table would be solving a problem this design doesn't
 * have: Cloudflare's webhook is "here is this video's current status,"
 * not a discrete, non-repeatable event log.
 *
 * **Publishing on `ready`**: a video imported by `VideoImporter` is
 * always created `draft` (Cloudflare hadn't confirmed it was playable
 * yet). The first time a webhook reports `ready`, and only if the post
 * is still `draft`, this class publishes it — `VideoLifecycleEvents`
 * (Phase 2) already listens for that status transition and dispatches
 * `VIDEO_PUBLISHED` itself, so this class doesn't dispatch that one
 * directly.
 */
final class StreamStatusUpdater
{
    /**
     * Construct around the repository this reads/writes and the
     * dispatcher VIDEO_STREAM_STATUS_CHANGED goes through.
     *
     * @param VideoMetadataRepositoryInterface $metadata_repository Read from and written to.
     * @param Dispatcher                       $dispatcher          Announces a real status change.
     */
    public function __construct(
        private readonly VideoMetadataRepositoryInterface $metadata_repository,
        private readonly Dispatcher $dispatcher
    ) {
    }

    /**
     * Apply a validated status update.
     *
     * @param string         $cf_stream_uid    The Cloudflare Stream UID this update is about.
     * @param CfStreamStatus $status           The reported status.
     * @param int|null       $duration_seconds The reported duration, if Cloudflare included one.
     *
     * @throws InvalidArgumentException If no video's metadata references this UID.
     */
    public function handle(string $cf_stream_uid, CfStreamStatus $status, ?int $duration_seconds): void
    {
        $video_id = $this->metadata_repository->find_video_id_by_stream_uid($cf_stream_uid);

        if (null === $video_id) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception message, never rendered as HTML output (caught by WebhookController and placed into a JSON REST response, not printed as markup).
            throw new InvalidArgumentException("No video found for Cloudflare Stream UID \"{$cf_stream_uid}\".");
        }

        $current_status = $this->metadata_repository->status_for($video_id);

        if ($current_status === $status && null === $duration_seconds) {
            return;
        }

        $this->metadata_repository->update_status($video_id, $status, $duration_seconds);

        if ($current_status !== $status) {
            $this->dispatcher->dispatch(
                EventCatalog::VIDEO_STREAM_STATUS_CHANGED,
                [
                    'video_id' => $video_id,
                    'status'   => $status->value,
                ]
            );
        }

        if (CfStreamStatus::Ready === $status) {
            $this->maybe_publish($video_id);
        }
    }

    /**
     * Publish a video if it's still sitting as a draft — a no-op if it's
     * already published, trashed, or otherwise not a draft.
     *
     * @param int $video_id The video to maybe publish.
     */
    private function maybe_publish(int $video_id): void
    {
        $post = get_post($video_id);

        if (! $post instanceof WP_Post || 'draft' !== $post->post_status) {
            return;
        }

        $result = wp_update_post(
            [
                'ID'          => $video_id,
                'post_status' => 'publish',
            ],
            true
        );

        if ($result instanceof WP_Error) {
            $message = '[tube-core] Auto-publish on Cloudflare Stream "ready" failed for video '
                . $video_id . ': ' . $result->get_error_message();

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate production logging for a non-critical side effect that failed; the status/duration update above already succeeded, so this isn't a reason to fail the webhook response.
            error_log($message);
        }
    }
}
