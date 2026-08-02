<?php
/**
 * `POST /tube/v1/webhooks/cloudflare-stream`.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Stream;

use InvalidArgumentException;
use Tube_Core\Video\CfStreamStatus;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /tube/v1/webhooks/cloudflare-stream` — receives Cloudflare
 * Stream's encoding-status webhook, per ARCHITECTURE.md §12 Phase 5.
 *
 * HTTP-layer concerns only: signature verification (via
 * `permission_callback`, WordPress's own mechanism for gating a route —
 * there is no WordPress user/nonce involved, since Cloudflare is not a
 * logged-in user; the HMAC signature is what stands in for
 * authentication here) and request-body parsing/validation. All actual
 * decision-making is `StreamStatusUpdater`'s, which this class only
 * calls with already-validated primitives.
 *
 * **Expected payload** (the one assumption in this phase that cannot be
 * verified without a live Cloudflare account — see PHASE-5.md): a JSON
 * body with a top-level `uid` (string, required) and `status` (either a
 * plain string, or an object with a `state` key — both forms are
 * accepted, since Cloudflare's Stream API itself uses the nested-object
 * shape for video resources but webhook notification payloads have
 * historically been simpler; accepting both is a deliberate hedge, not
 * a guess presented as certainty), and an optional numeric top-level
 * `duration` (seconds). **Verify this against a real webhook delivery
 * before relying on it in production.**
 */
final class WebhookController
{
    /**
     * Construct around the collaborators this controller delegates to.
     *
     * @param WebhookSignatureVerifier $signature_verifier Verifies the request actually came from Cloudflare.
     * @param StreamStatusUpdater      $status_updater      Applies a validated status update.
     */
    public function __construct(
        private readonly WebhookSignatureVerifier $signature_verifier,
        private readonly StreamStatusUpdater $status_updater
    ) {
    }

    /**
     * `permission_callback`: verify the request's HMAC signature.
     *
     * Fails closed: an unconfigured secret means every request is
     * rejected, never silently accepted (ARCHITECTURE.md §12 Phase 5 —
     * "never trust client input").
     *
     * @param WP_REST_Request $request The incoming request.
     */
    public function check_signature(WP_REST_Request $request): bool|WP_Error
    {
        $secret = defined('TUBE_CORE_CLOUDFLARE_STREAM_WEBHOOK_SECRET')
            ? TUBE_CORE_CLOUDFLARE_STREAM_WEBHOOK_SECRET
            : '';

        // A defined constant's value has no static type WordPress/PHPStan
        // can verify — is_string() both confirms it for real (docker-compose.yml
        // always defines this one as a string, but nothing enforces that
        // at the language level) and satisfies verify()'s own strict
        // `string $secret` parameter honestly, not via a bare cast.
        if (! is_string($secret) || '' === $secret) {
            return new WP_Error(
                'tube_core_webhook_not_configured',
                'The Cloudflare Stream webhook secret is not configured.',
                ['status' => 503]
            );
        }

        $signature_header = $request->get_header('webhook-signature');

        if (null === $signature_header || '' === $signature_header) {
            return new WP_Error(
                'tube_core_webhook_missing_signature',
                'Missing Webhook-Signature header.',
                ['status' => 401]
            );
        }

        $verified = $this->signature_verifier->verify(
            $request->get_body(),
            $signature_header,
            $secret
        );

        if (! $verified) {
            return new WP_Error('tube_core_webhook_invalid_signature', 'Invalid webhook signature.', ['status' => 401]);
        }

        return true;
    }

    /**
     * The route callback: parse, validate, and apply the status update.
     *
     * @param WP_REST_Request $request The incoming request (signature already verified by check_signature()).
     */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $data = json_decode($request->get_body(), true);

        if (! is_array($data)) {
            return new WP_REST_Response(['error' => 'Request body is not valid JSON.'], 400);
        }

        if (! isset($data['uid']) || ! is_string($data['uid']) || '' === trim($data['uid'])) {
            return new WP_REST_Response(['error' => 'Missing or invalid "uid".'], 400);
        }

        $status_raw = $data['status'] ?? null;

        if (is_array($status_raw) && isset($status_raw['state'])) {
            $status_raw = $status_raw['state'];
        }

        if (! is_string($status_raw)) {
            return new WP_REST_Response(['error' => 'Missing or invalid "status".'], 400);
        }

        $status = CfStreamStatus::tryFrom($status_raw);

        if (null === $status) {
            return new WP_REST_Response(['error' => "Unrecognized status \"{$status_raw}\"."], 400);
        }

        $duration_seconds = null;

        if (isset($data['duration']) && is_numeric($data['duration'])) {
            $duration_float   = (float) $data['duration'];
            $duration_seconds = (int) round($duration_float);
        }

        try {
            $this->status_updater->handle($data['uid'], $status, $duration_seconds);
        } catch (InvalidArgumentException $exception) {
            return new WP_REST_Response(['error' => $exception->getMessage()], 404);
        }

        return new WP_REST_Response(['success' => true], 200);
    }
}
