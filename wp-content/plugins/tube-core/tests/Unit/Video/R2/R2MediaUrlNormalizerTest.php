<?php
/**
 * Unit tests for R2MediaUrlNormalizer.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Video\R2;

use PHPUnit\Framework\TestCase;
use Tube_Core\Video\R2\R2MediaUrlNormalizer;

/**
 * Exercises `normalize()`/`public_url()` against this feature's own real
 * R2 example (a genuine Vietnamese filename with combining diacritics,
 * percent-encoded with literal spaces as `%20`) — the concrete proof
 * that a real-world accented filename round-trips byte-for-byte back to
 * the exact URL it came from, plus every rejection path the security
 * requirements call for (wrong host, wrong scheme, path traversal).
 */
final class R2MediaUrlNormalizerTest extends TestCase
{
    /**
     * This feature's real R2 example, exactly as given.
     */
    private const REAL_URL = 'https://media.nangcuctvc.com/'
        . 'EM%20Tu%CC%81%20Nhie%CC%82n%20Qua%CC%89ng%20Ninh%20nangcuc.mp4';

    /**
     * The normalizer under test.
     *
     * @var R2MediaUrlNormalizer
     */
    private R2MediaUrlNormalizer $normalizer;

    /**
     * Construct around the real example's own base URL.
     */
    protected function setUp(): void
    {
        $this->normalizer = new R2MediaUrlNormalizer('https://media.nangcuctvc.com');
    }

    /**
     * The real example URL normalizes to a decoded key and resolves back
     * to the exact same URL — the concrete round-trip proof this
     * feature's own requirements call for.
     */
    public function test_real_example_url_round_trips_exactly(): void
    {
        $object_key = $this->normalizer->normalize(self::REAL_URL);

        self::assertNotNull($object_key);
        self::assertSame(self::REAL_URL, $this->normalizer->public_url($object_key));
    }

    /**
     * A bare object key (no scheme/host at all) is accepted as-is.
     */
    public function test_bare_object_key_is_accepted(): void
    {
        self::assertSame('videos/clip.mp4', $this->normalizer->normalize('videos/clip.mp4'));
    }

    /**
     * A bare key with a leading slash is treated the same as without one.
     */
    public function test_bare_object_key_leading_slash_is_stripped(): void
    {
        self::assertSame('videos/clip.mp4', $this->normalizer->normalize('/videos/clip.mp4'));
    }

    /**
     * A percent-encoded bare key (not a full URL) is still decoded.
     */
    public function test_percent_encoded_bare_key_is_decoded(): void
    {
        self::assertSame('vo em.mp4', $this->normalizer->normalize('vo%20em.mp4'));
    }

    /**
     * Double-encoded input collapses to the single real decoded value,
     * not a half-decoded intermediate — the project's own explicit
     * "double URL encoding" risk.
     */
    public function test_double_encoded_input_collapses_correctly(): void
    {
        // "%20" double-encoded is "%2520".
        self::assertSame('vo em.mp4', $this->normalizer->normalize('vo%2520em.mp4'));
    }

    /**
     * A full URL whose host doesn't match the configured base is
     * rejected outright — the core SSRF-adjacent protection this
     * feature's security requirements call for.
     */
    public function test_full_url_with_a_different_host_is_rejected(): void
    {
        self::assertNull($this->normalizer->normalize('https://evil.example.com/clip.mp4'));
    }

    /**
     * A host that merely starts with/contains the configured host as a
     * substring is still rejected — an exact match only, never a
     * suffix/prefix match an attacker could exploit.
     */
    public function test_full_url_with_a_host_suffix_trick_is_rejected(): void
    {
        self::assertNull($this->normalizer->normalize('https://media.nangcuctvc.com.evil.example/clip.mp4'));
        self::assertNull($this->normalizer->normalize('https://evil-media.nangcuctvc.com/clip.mp4'));
    }

    /**
     * A non-http(s) scheme on an otherwise-matching host is rejected.
     */
    public function test_non_http_scheme_is_rejected(): void
    {
        self::assertNull($this->normalizer->normalize('ftp://media.nangcuctvc.com/clip.mp4'));
    }

    /**
     * A key containing a path-traversal segment is rejected.
     */
    public function test_path_traversal_is_rejected(): void
    {
        self::assertNull($this->normalizer->normalize('../../etc/passwd'));
        self::assertNull($this->normalizer->normalize('videos/../../../etc/passwd'));
    }

    /**
     * Empty input is rejected.
     */
    public function test_empty_input_is_rejected(): void
    {
        self::assertNull($this->normalizer->normalize(''));
        self::assertNull($this->normalizer->normalize('   '));
    }

    /**
     * `public_url()` re-encodes a multi-segment key's slashes correctly
     * (each path segment percent-encoded independently, the literal '/'
     * separators preserved, never themselves encoded to %2F).
     */
    public function test_public_url_preserves_path_separators(): void
    {
        self::assertSame(
            'https://media.nangcuctvc.com/videos/2026/clip%20one.mp4',
            $this->normalizer->public_url('videos/2026/clip one.mp4')
        );
    }

    /**
     * `public_url()` works whether the configured base URL has a trailing
     * slash or not.
     */
    public function test_public_url_handles_trailing_slash_on_base_url(): void
    {
        $with_slash = new R2MediaUrlNormalizer('https://media.nangcuctvc.com/');

        self::assertSame(
            'https://media.nangcuctvc.com/clip.mp4',
            $with_slash->public_url('clip.mp4')
        );
    }
}
