<?php
/**
 * StreamDetailsProviderInterface implementation backed by the real Cloudflare Stream API.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Stream;

use Tube_Core\Video\CfStreamStatus;
use Tube_Core\Video\StreamDetails;
use WP_Error;

/**
 * StreamDetailsProviderInterface implementation backed by the real
 * Cloudflare Stream API (`GET /accounts/{account_id}/stream/{uid}`).
 * WordPress-coupled (`wp_remote_get()`) and integration-tested only, the
 * same split `Tube_Admin\Media\CloudflareImagesUploader` (removed by
 * ADR-0001, but the same real-outbound-Cloudflare-call pattern) already
 * established.
 *
 * Requires a Stream:Read-scoped API token — distinct from
 * `TUBE_PLAYER_CLOUDFLARE_STREAM_CUSTOMER_CODE` (the delivery-URL
 * subdomain segment `tube-player` uses, which cannot authenticate an API
 * read), the same `ACCOUNT_ID`/`API_TOKEN`-vs-delivery-identifier split
 * `CloudflareImagesUploader` already established for Images.
 *
 * **Never throws, never surfaces a distinct "why it failed" signal to
 * its caller** — every failure mode (unconfigured credentials, network
 * error, non-2xx status, malformed body, video not found on this
 * account) returns `null` uniformly, per
 * `StreamDetailsProviderInterface::fetch()`'s own contract: the correct
 * response to all of these is identical (leave existing data untouched),
 * so there is nothing a caller would do differently by exception type —
 * distinguishing them would be dead branching, not real behavior.
 */
final class CloudflareStreamDetailsFetcher implements StreamDetailsProviderInterface
{
    /**
     * Cloudflare's API base URL.
     */
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    /**
     * Construct the fetcher with its Cloudflare credentials.
     *
     * @param string $account_id The Cloudflare account ID (not the delivery-URL "customer code" tube-player uses).
     * @param string $api_token  A Cloudflare API token scoped to Stream:Read.
     */
    public function __construct(
        private readonly string $account_id,
        private readonly string $api_token
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param string $cf_stream_uid The Cloudflare Stream UID to look up.
     */
    public function fetch(string $cf_stream_uid): ?StreamDetails
    {
        if ('' === $this->account_id || '' === $this->api_token) {
            return null;
        }

        $response = wp_remote_get(
            self::API_BASE . "/accounts/{$this->account_id}/stream/{$cf_stream_uid}",
            [
                'headers' => ['Authorization' => 'Bearer ' . $this->api_token],
                'timeout' => 10,
            ]
        );

        $result = self::successful_result($response);

        if (null === $result) {
            return null;
        }

        $state_raw = is_array($result['status'] ?? null) ? ($result['status']['state'] ?? null) : null;

        if (! is_string($state_raw)) {
            return null;
        }

        $duration_raw   = $result['duration'] ?? null;
        $duration_valid = (is_int($duration_raw) || is_float($duration_raw)) && $duration_raw >= 0;
        $duration_float = $duration_valid ? (float) $duration_raw : 0.0;
        $rounded        = round($duration_float);

        $duration_seconds = $duration_valid ? (int) $rounded : null;

        return new StreamDetails(self::map_status($state_raw), $duration_seconds);
    }

    /**
     * Validate an HTTP response as a successful Cloudflare API call and
     * return its decoded `result` object, or null if anything about the
     * response indicates failure.
     *
     * @param array<string, mixed>|WP_Error $response The raw wp_remote_get() result.
     *
     * @return array<string, mixed>|null
     */
    private static function successful_result(array|WP_Error $response): ?array
    {
        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);

        if (! is_int($code) || $code < 200 || $code >= 300) {
            return null;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        if (! is_array($decoded) || true !== ($decoded['success'] ?? null) || ! is_array($decoded['result'] ?? null)) {
            return null;
        }

        $result = $decoded['result'];

        /** @var array<string, mixed> $result */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        return $result;
    }

    /**
     * Map a Cloudflare Stream API `status.state` value to this project's
     * own, narrower `CfStreamStatus` enum. Cloudflare's states
     * (`pendingupload`, `downloading`, `queued`, `inprogress`, `ready`,
     * `error`) are collapsed to this project's four: everything before
     * `ready`/`error` that isn't specifically "hasn't started uploading
     * yet" is `Processing` — the distinction beyond that has no consumer
     * anywhere in this codebase (`ARCHITECTURE.md` §11's `cf_status`
     * column is `ENUM('pending','processing','ready','error')`).
     *
     * @param string $state Cloudflare's raw `status.state` value.
     */
    private static function map_status(string $state): CfStreamStatus
    {
        return match ($state) {
            'ready' => CfStreamStatus::Ready,
            'error' => CfStreamStatus::Error,
            'pendingupload' => CfStreamStatus::Pending,
            default => CfStreamStatus::Processing,
        };
    }
}
