<?php
/**
 * Synchronizes one video's status/duration from a live Cloudflare Stream lookup.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Stream;

use InvalidArgumentException;

/**
 * Synchronizes one video's status/duration from a live Cloudflare Stream
 * lookup (`StreamDetailsProviderInterface`) by applying it through
 * `StreamStatusUpdater::handle()` — the exact same entry point the
 * webhook handler itself calls. This is the pull-based counterpart to
 * that push-based flow, not a second/competing mechanism: composing
 * `StreamStatusUpdater` (rather than writing to
 * `VideoMetadataRepositoryInterface::update_status()` directly) is
 * deliberate, not just for the write itself, but for everything
 * `StreamStatusUpdater::handle()` already does around it — dispatching
 * `EventCatalog::VIDEO_STREAM_STATUS_CHANGED` on a real status change
 * (which `Tube_Search\Events\SearchIndexSyncSubscriber` and
 * `Tube_Cache\Events\CachePurgeSubscriber` both listen for) and
 * auto-publishing a still-draft video once Cloudflare reports `ready`.
 * Writing to the repository directly here would have silently skipped
 * all of that for a manually-entered UID — the exact "changed one
 * renderer/service without updating all dependent interfaces/callers"
 * mistake this project has already been asked to avoid once.
 *
 * **Never corrupts existing data on failure**: if the lookup fails for
 * any reason (unconfigured credentials, network error, unrecognized
 * UID), `fetch()` returns `null` and this method does nothing at all —
 * the video's existing `cf_status`/`duration_seconds` stay exactly as
 * they were.
 */
final class StreamMetadataSyncer
{
    /**
     * Construct around the collaborators this orchestrator composes.
     *
     * @param StreamDetailsProviderInterface $details_provider Resolves a UID's live Cloudflare Stream details.
     * @param StreamStatusUpdater            $status_updater   Applies a successful lookup's result.
     */
    public function __construct(
        private readonly StreamDetailsProviderInterface $details_provider,
        private readonly StreamStatusUpdater $status_updater
    ) {
    }

    /**
     * Fetch and apply one video's current Cloudflare Stream details, if available.
     *
     * @param string $cf_stream_uid The Cloudflare Stream UID to look up (and, on success, update).
     *
     * @return bool Whether the lookup succeeded and the video's status/duration were updated.
     */
    public function sync(string $cf_stream_uid): bool
    {
        $details = $this->details_provider->fetch($cf_stream_uid);

        if (null === $details) {
            return false;
        }

        try {
            $this->status_updater->handle($cf_stream_uid, $details->status, $details->duration_seconds);
        } catch (InvalidArgumentException $exception) {
            // StreamStatusUpdater::handle() throws if no video's metadata
            // references this UID yet — a genuine race (the caller's own
            // create()/update_stream_uid() call, immediately before this
            // one, is what's supposed to guarantee it exists) rather than
            // a normal outcome, but this class's own contract is "return
            // false on any failure, never throw," so it's caught here
            // rather than left to propagate.
            unset($exception);

            return false;
        }

        return true;
    }
}
