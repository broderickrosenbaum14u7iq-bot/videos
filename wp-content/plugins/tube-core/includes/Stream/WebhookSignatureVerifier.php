<?php
/**
 * Verifies a Cloudflare Stream webhook's HMAC signature.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Stream;

/**
 * Verifies a Cloudflare Stream webhook's HMAC signature, per
 * ARCHITECTURE.md §12 Phase 5 ("Validate every webhook request. Reject
 * invalid payloads. Never trust client input.").
 *
 * Cloudflare signs webhook requests with a `Webhook-Signature` header
 * shaped `time=<unix timestamp>,sig1=<hex HMAC-SHA256>`, where the
 * signed message is `"{time}.{raw request body}"`. Verification here
 * checks two independent things, both required: the signature itself
 * (proves the request actually came from someone holding the shared
 * secret, via `hash_equals()` — constant-time, so comparing signatures
 * can't leak timing information an attacker could use to guess a valid
 * one byte-by-byte) and the timestamp's freshness (rejects a
 * captured-and-replayed request outside a 5-minute window, even if its
 * signature is genuinely valid).
 */
final class WebhookSignatureVerifier
{
    /**
     * How far a signature's timestamp may drift from now (either
     * direction) before it's rejected as stale/replayed.
     */
    private const MAX_AGE_SECONDS = 300;

    /**
     * Verify a webhook request's signature.
     *
     * @param string $body              The raw (unparsed) request body — the signature covers these exact bytes.
     * @param string $signature_header  The full `Webhook-Signature` header value.
     * @param string $secret            The shared webhook secret.
     */
    public function verify(string $body, string $signature_header, string $secret): bool
    {
        $parsed = $this->parse($signature_header);

        if (null === $parsed) {
            return false;
        }

        [$timestamp, $signature] = $parsed;

        if (abs(time() - $timestamp) > self::MAX_AGE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Parse `time=<int>,sig1=<hex>` into its two parts.
     *
     * @param string $signature_header The full `Webhook-Signature` header value.
     *
     * @return array{0: int, 1: string}|null The timestamp and signature, or null if the header is malformed.
     */
    private function parse(string $signature_header): ?array
    {
        $time = null;
        $sig1 = null;

        foreach (explode(',', $signature_header) as $pair) {
            $segments = explode('=', $pair, 2);

            if (2 !== count($segments)) {
                continue;
            }

            [$key, $value] = $segments;

            if ('time' === $key) {
                $time = $value;
            } elseif ('sig1' === $key) {
                $sig1 = $value;
            }
        }

        if (null === $time || null === $sig1 || ! ctype_digit($time) || '' === $sig1) {
            return null;
        }

        $timestamp = (int) $time;

        return [$timestamp, $sig1];
    }
}
