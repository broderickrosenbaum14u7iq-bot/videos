<?php
/**
 * A snapshot of one video's details as reported live by Cloudflare Stream's API.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Video;

/**
 * A snapshot of one video's details as reported live by Cloudflare
 * Stream's API — the pull-based counterpart to the push-based webhook
 * payload `Tube_Core\Stream\StreamStatusUpdater` already applies. Both
 * ultimately feed the same `VideoMetadataRepositoryInterface::update_status()`
 * write path (ARCHITECTURE.md §2.1) — this is not a second/competing
 * status-and-duration model, just a second way of *obtaining* the same
 * two fields for the case where Cloudflare never had a reason to push a
 * webhook for this UID (a manually-entered Stream UID for a video that
 * already exists on the account, never routed through this project's own
 * upload/import flow).
 */
final class StreamDetails
{
    /**
     * Construct an immutable snapshot of one video's live Cloudflare Stream details.
     *
     * @param CfStreamStatus $status           The video's current encoding status, as reported live.
     * @param int|null       $duration_seconds The video's duration, if Cloudflare has determined it yet.
     */
    public function __construct(
        public readonly CfStreamStatus $status,
        public readonly ?int $duration_seconds
    ) {
    }
}
