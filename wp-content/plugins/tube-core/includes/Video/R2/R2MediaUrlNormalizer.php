<?php
/**
 * Normalizes admin-submitted R2 input (a full URL or a bare object key) to a canonical object key.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Video\R2;

/**
 * Normalizes admin-submitted R2 input — either a full
 * `https://<configured-base>/<key>` URL or a bare object key/path — down
 * to one canonical, already-percent-decoded object key, and resolves a
 * stored key back to the real public URL at render time. The only thing
 * ever persisted is the object key (ARCHITECTURE.md §2.1's "no playback
 * URL ever stored" rule, extended to this second source); the base URL
 * lives in one place ({@see self::$base_url}, itself sourced from the
 * `TUBE_CORE_R2_MEDIA_BASE_URL` constant by whichever plugin constructs
 * this) so it's never hardcoded into a template.
 *
 * **Security property the two methods below are built around**: a
 * normalized object key is never itself a host — {@see self::public_url()}
 * always anchors it to {@see self::$base_url}, the one admin-configured,
 * trusted domain. A full URL is only ever accepted as input when its
 * host matches that exact configured host (`strcasecmp`, not a substring/
 * suffix match — `media.nangcuctvc.com.evil.example` is correctly
 * rejected); anything else (a different host, a non-http(s) scheme) is
 * rejected outright by {@see self::normalize()} rather than silently
 * stored as a literal string that might later resemble a host. The net
 * effect: this class can never be tricked into producing a URL that
 * points anywhere other than the one configured R2 domain — there is no
 * SSRF surface here regardless of what's typed into the admin field.
 *
 * **Percent-encoding**: {@see self::normalize()} decodes its input at
 * most a few times (collapsing legitimate double-encoding, never
 * further once a decode round-trips to a stable value) and
 * {@see self::public_url()} re-encodes each path segment independently —
 * together, a real-world accented/Vietnamese filename round-trips byte-
 * for-byte back to the exact URL it came from (verified live against
 * this project's real R2 example during implementation).
 */
final class R2MediaUrlNormalizer
{
    /**
     * How many times self::decode_once_stable() will try to decode a
     * value before giving up — bounds the cost of a pathological,
     * deeply-nested-percent-encoded input; real double-encoding never
     * needs more than two passes.
     */
    private const MAX_DECODE_PASSES = 4;

    /**
     * Construct around the one configured R2 base URL.
     *
     * @param string $base_url The public R2 base URL, e.g. `https://media.nangcuctvc.com`. Trailing slash optional.
     */
    public function __construct(private readonly string $base_url)
    {
    }

    /**
     * Normalize admin-submitted input to a canonical object key.
     *
     * @param string $input Either a full URL (must match the configured base host exactly) or a bare object key.
     *
     * @return string|null The canonical, decoded object key, or null if `$input` is empty, malformed, targets a
     *                      different host, or otherwise fails validation.
     */
    public function normalize(string $input): ?string
    {
        $input = trim($input);

        if ('' === $input) {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- deliberately the native function, not wp_parse_url(): this class has no other WordPress dependency at all, kept that way specifically so it's real-unit-testable (no WP bootstrap needed) rather than integration-test-only like every other WordPress-coupled class in this project.
        $parsed = parse_url($input);
        $scheme = is_array($parsed) ? ($parsed['scheme'] ?? null) : null;
        $host   = is_array($parsed) ? ($parsed['host'] ?? null) : null;
        $path   = is_array($parsed) ? ($parsed['path'] ?? '') : '';

        if (is_string($scheme) && is_string($host)) {
            // Looks like a full URL -- only accept it if it's actually
            // ours; reject everything else outright rather than storing
            // it as a (harmless, per this class's own docblock, but
            // confusing) literal key.
            if (! in_array(strtolower($scheme), ['http', 'https'], true)) {
                return null;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- see the matching ignore comment above; same reasoning applies here.
            $base_host = parse_url($this->base_url, PHP_URL_HOST);

            if (! is_string($base_host) || 0 !== strcasecmp($host, $base_host)) {
                return null;
            }

            $key = ltrim($path, '/');
        } else {
            $key = ltrim($input, '/');
        }

        $key = self::decode_once_stable($key);

        if ('' === $key || str_contains($key, '..') || 1 === preg_match('/[\x00-\x1f]/', $key)) {
            return null;
        }

        return $key;
    }

    /**
     * Resolve a stored object key back to its real, fetchable public URL.
     *
     * @param string $object_key The canonical object key, as returned by {@see self::normalize()}.
     */
    public function public_url(string $object_key): string
    {
        $encoded_segments = array_map('rawurlencode', explode('/', $object_key));

        return rtrim($this->base_url, '/') . '/' . implode('/', $encoded_segments);
    }

    /**
     * Calls rawurldecode() repeatedly until the result stops changing (or
     * {@see self::MAX_DECODE_PASSES} is reached) — collapses legitimate
     * double-encoding while naturally stopping at the correct point for
     * already-single-encoded or entirely-unencoded input, since a value
     * with no more `%XX` sequences decodes to itself and the loop exits
     * on the very next pass either way.
     *
     * @param string $value The raw (possibly percent-encoded, possibly double-encoded) path segment.
     */
    private static function decode_once_stable(string $value): string
    {
        $decoded = $value;

        for ($i = 0; $i < self::MAX_DECODE_PASSES; $i++) {
            $next = rawurldecode($decoded);

            if ($next === $decoded) {
                break;
            }

            $decoded = $next;
        }

        return $decoded;
    }
}
