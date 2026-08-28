<?php
/**
 * Resolves the current request's client IP for rate-limiting purposes.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Support;

/**
 * Resolves the current request's client IP for rate-limiting purposes
 * only (Phase 21: "Do not trust IP alone as permanent identity" — this
 * is used purely to bound anonymous pre-auth abuse, never as an account
 * identity). Deliberately reads only `REMOTE_ADDR` — the one header a
 * client cannot spoof at the TCP layer — never `X-Forwarded-For` (this
 * project's deployment has no documented trusted-proxy configuration,
 * so honoring a client-supplied forwarded-for header here would let any
 * caller bypass the limiter entirely by sending an arbitrary value).
 */
final class ClientIp
{
    /**
     * The current request's IP, or '0.0.0.0' if unavailable (never
     * throws — a rate limiter still needs a key even for a malformed
     * request environment such as a unit test or CLI context).
     */
    public static function resolve(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($ip) && '' !== $ip ? $ip : '0.0.0.0';
    }
}
