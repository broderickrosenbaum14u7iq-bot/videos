<?php
/**
 * Signs short-lived, HMAC-authenticated R2 playback URLs.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Video\R2;

/**
 * Signs short-lived, HMAC-authenticated R2 playback URLs — the only way
 * this project's browser-facing code is ever allowed to reference an R2
 * object, once the bucket is private
 * (`infrastructure/cloudflare/r2-media-worker/README.md`'s "what you
 * must configure in Cloudflare" covers making that change). The
 * permanent, unsigned `R2MediaUrlNormalizer::public_url()` is no longer
 * fetchable directly by a browser after that change — this class is
 * composed around one (reusing its path-encoding logic, never
 * duplicating it) specifically to turn that permanent, unsigned URL into
 * a temporary, signed one.
 *
 * **What the signature binds**: the object key (exactly as stored —
 * the canonical, decoded form `R2MediaUrlNormalizer::normalize()`
 * already produces) and the expiry timestamp, joined by a literal `\n`
 * — never anything else, and never less. A signature for one object/
 * expiry pair is meaningless for any other object or any other expiry;
 * changing either invalidates it. The Cloudflare Worker
 * (`infrastructure/cloudflare/r2-media-worker/src/index.js`) is the
 * other half of this contract: it reconstructs the exact same message
 * from the incoming request (URL-decoded path + `exp` query param) and
 * verifies it with the same secret via `crypto.subtle.verify()` — never
 * trust-on-first-use, never a bare string comparison on that side
 * either (Web Crypto's HMAC verify is constant-time by construction).
 *
 * **The secret never leaves PHP/the Worker's own environment**: not
 * logged, not embedded in any response this class's own output reaches
 * (the signed URL itself reveals nothing about the secret — that's the
 * entire point of HMAC), and never passed to this constructor as
 * anything but a value read from a `wp-config.php` constant by the
 * caller (`Tube_Core\Plugin::r2_playback_url_signer()`).
 */
final class R2PlaybackUrlSigner
{
    /**
     * Default signed-URL lifetime, in seconds — 10 minutes, per this
     * feature's own stated expiry target. Long enough that a normal
     * page load-then-click-play-then-watch doesn't race expiry for a
     * typical video length, short enough that a copied URL stops
     * working well within the same viewing session if shared elsewhere.
     */
    public const DEFAULT_TTL_SECONDS = 600;

    /**
     * Construct around the normalizer this reuses for URL-building, the
     * signing secret, and the signed URL's lifetime.
     *
     * @param R2MediaUrlNormalizer $normalizer  Builds the base `scheme://host/encoded-key` URL this appends
     *                                          `?exp=&sig=` to.
     * @param string               $secret      The shared HMAC secret — `TUBE_CORE_R2_SIGNING_SECRET`, never
     *                                          logged/exposed.
     * @param int                  $ttl_seconds How long a freshly-signed URL remains valid, in seconds.
     */
    public function __construct(
        private readonly R2MediaUrlNormalizer $normalizer,
        private readonly string $secret,
        private readonly int $ttl_seconds = self::DEFAULT_TTL_SECONDS
    ) {
    }

    /**
     * Sign a temporary playback URL for one object key, expiring
     * `self::$ttl_seconds` from now.
     *
     * Returns the permanent, unsigned URL unchanged (no `?exp=&sig=`
     * appended) if no secret is configured — the same fail-open-to-the-
     * previous-behavior posture this project already applies elsewhere
     * for an unconfigured integration (`CloudflareStreamDetailsFetcher`),
     * rather than a fatal or a broken empty URL; whether that unsigned
     * URL is actually fetchable is then purely a function of whether
     * the R2 bucket itself is still public (pre-Worker-deployment state)
     * or already private (post-deployment state) — this class does not
     * need to know which.
     *
     * @param string $object_key The canonical object key to sign.
     */
    public function sign_url(string $object_key): string
    {
        $base_url = $this->normalizer->public_url($object_key);

        if ('' === $this->secret) {
            return $base_url;
        }

        $expires_at = time() + $this->ttl_seconds;
        $signature  = self::compute_signature($object_key, $expires_at, $this->secret);

        $separator = str_contains($base_url, '?') ? '&' : '?';

        return $base_url . $separator . 'exp=' . $expires_at . '&sig=' . $signature;
    }

    /**
     * Verify a previously-issued signature — the PHP-side reference
     * implementation of the exact check the Cloudflare Worker performs
     * in JavaScript, kept here purely so this class's own cryptographic
     * contract (what gets signed, how expiry/tampering are rejected) has
     * a deterministic, fast, WordPress-independent unit test covering
     * it directly; WordPress itself never calls this in a real request
     * (only the Worker verifies — see this class's own docblock).
     *
     * @param string $object_key   The object key the caller claims this signature is for.
     * @param int    $expires_at   The expiry timestamp the caller claims this signature is for.
     * @param string $signature    The signature to check.
     * @param int    $current_time The current time to check expiry against (injectable for deterministic tests).
     */
    public function verify(string $object_key, int $expires_at, string $signature, int $current_time): bool
    {
        if ('' === $this->secret) {
            return false;
        }

        if ($current_time > $expires_at) {
            return false;
        }

        $expected = self::compute_signature($object_key, $expires_at, $this->secret);

        return hash_equals($expected, $signature);
    }

    /**
     * The HMAC-SHA256 signature for one object key/expiry pair — the
     * exact message format (`"{$object_key}\n{$expires_at}"`) the
     * Cloudflare Worker reconstructs and verifies against.
     *
     * @param string $object_key The canonical object key.
     * @param int    $expires_at Unix timestamp the signature is valid until.
     * @param string $secret     The shared HMAC secret.
     */
    private static function compute_signature(string $object_key, int $expires_at, string $secret): string
    {
        return hash_hmac('sha256', $object_key . "\n" . $expires_at, $secret);
    }
}
