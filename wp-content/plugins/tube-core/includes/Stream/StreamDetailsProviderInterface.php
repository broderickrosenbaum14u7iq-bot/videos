<?php
/**
 * Contract for fetching one video's live details from Cloudflare Stream's API.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Stream;

use Tube_Core\Video\StreamDetails;

/**
 * Contract for fetching one video's live details (status, duration) from
 * Cloudflare Stream's API — the pull-based counterpart to the
 * push-based webhook `WebhookController`/`StreamStatusUpdater` already
 * handle. Used by `Tube_Admin\Video\StreamUidMetaBox` to synchronize a
 * manually-entered Stream UID's status/duration immediately, since a
 * manually-entered UID (referencing a video already on the account, not
 * one this project's own import pipeline uploaded) has no reason to ever
 * receive a webhook for it.
 *
 * Adopted per the interface-justification rule (ARCHITECTURE.md §19.1) —
 * the same basis `VideoProviderInterface` was adopted on: the real payoff
 * is a test fake `StreamUidMetaBox`'s own tests use to exercise the
 * sync-on-save behavior (success, not-found, and network-failure paths)
 * without a live Cloudflare account.
 */
interface StreamDetailsProviderInterface
{
    /**
     * Fetch one video's current details.
     *
     * @param string $cf_stream_uid The Cloudflare Stream UID to look up.
     *
     * @return StreamDetails|null The video's details, or null if they
     *                            could not be determined for any reason
     *                            (not configured, network failure, bad
     *                            response, or the UID doesn't exist on
     *                            this account) — deliberately not
     *                            distinguished by exception type, since
     *                            every caller's correct response to all
     *                            of these is identical: leave whatever is
     *                            already stored untouched, per this
     *                            interface's "never corrupt on failure"
     *                            contract.
     */
    public function fetch(string $cf_stream_uid): ?StreamDetails;
}
