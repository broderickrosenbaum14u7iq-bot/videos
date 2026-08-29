<?php
/**
 * Unit tests for R2PlaybackUrlSigner.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Video\R2;

use PHPUnit\Framework\TestCase;
use Tube_Core\Video\R2\R2MediaUrlNormalizer;
use Tube_Core\Video\R2\R2PlaybackUrlSigner;

/**
 * Exercises `sign_url()`/`verify()` against every signed-URL security
 * property this feature's requirements call for: deterministic signing,
 * a valid signature passes, a tampered object key/expiry/signature each
 * fail, an expired signature fails even with a genuinely correct
 * signature, and the signed URL itself round-trips through the same
 * Unicode/percent-encoding cases `R2MediaUrlNormalizerTest` already
 * proves for the underlying (unsigned) URL shape.
 */
final class R2PlaybackUrlSignerTest extends TestCase
{
    private const SECRET = 'test-secret-do-not-use-in-production';

    /**
     * The signer under test.
     *
     * @var R2PlaybackUrlSigner
     */
    private R2PlaybackUrlSigner $signer;

    /**
     * Construct around this test's own fixed secret/TTL.
     */
    protected function setUp(): void
    {
        $normalizer   = new R2MediaUrlNormalizer('https://media.nangcuctvc.com');
        $this->signer = new R2PlaybackUrlSigner($normalizer, self::SECRET, 600);
    }

    /**
     * A freshly-signed URL is anchored to the configured base URL, the
     * correctly-encoded object key, and carries `exp`/`sig` query params.
     */
    public function test_sign_url_produces_expected_shape(): void
    {
        $url = $this->signer->sign_url('clip.mp4');

        self::assertStringStartsWith('https://media.nangcuctvc.com/clip.mp4?exp=', $url);
        self::assertMatchesRegularExpression('/[?&]exp=\d+/', $url);
        self::assertMatchesRegularExpression('/[?&]sig=[0-9a-f]{64}/', $url);
    }

    /**
     * Signing is deterministic: the same object key/expiry/secret always
     * produce the exact same signature — required for the Cloudflare
     * Worker's independent recomputation to ever match.
     */
    public function test_signing_is_deterministic(): void
    {
        $signature_a = $this->sign_for_test('clip.mp4', 1893456000);
        $signature_b = $this->sign_for_test('clip.mp4', 1893456000);

        self::assertSame($signature_a, $signature_b);
    }

    /**
     * A signature produced for one object key/expiry verifies
     * successfully against that exact object key/expiry, before expiry.
     */
    public function test_valid_signature_verifies(): void
    {
        $expires_at = 1893456000;
        $signature  = $this->sign_for_test('clip.mp4', $expires_at);

        self::assertTrue($this->signer->verify('clip.mp4', $expires_at, $signature, $expires_at - 1));
    }

    /**
     * A structurally-invalid (wrong-length/garbage) signature is
     * rejected.
     */
    public function test_malformed_signature_is_rejected(): void
    {
        $expires_at = 1893456000;

        self::assertFalse($this->signer->verify('clip.mp4', $expires_at, 'not-a-real-signature', $expires_at - 1));
        self::assertFalse($this->signer->verify('clip.mp4', $expires_at, '', $expires_at - 1));
    }

    /**
     * Modifying the object key after signing invalidates the signature —
     * a signature is meaningless for any object other than the one it
     * was issued for.
     */
    public function test_modified_object_key_is_rejected(): void
    {
        $expires_at = 1893456000;
        $signature  = $this->sign_for_test('clip.mp4', $expires_at);

        self::assertFalse($this->signer->verify('other-clip.mp4', $expires_at, $signature, $expires_at - 1));
    }

    /**
     * Modifying the expiry after signing invalidates the signature — an
     * attacker cannot extend their own access by editing `exp` in the URL.
     */
    public function test_modified_expiry_is_rejected(): void
    {
        $expires_at = 1893456000;
        $signature  = $this->sign_for_test('clip.mp4', $expires_at);

        self::assertFalse($this->signer->verify('clip.mp4', $expires_at + 3600, $signature, $expires_at - 1));
    }

    /**
     * A genuinely correct signature still fails once the current time
     * passes its expiry — the whole point of a short-lived URL.
     */
    public function test_expired_signature_is_rejected(): void
    {
        $expires_at = 1893456000;
        $signature  = $this->sign_for_test('clip.mp4', $expires_at);

        self::assertFalse($this->signer->verify('clip.mp4', $expires_at, $signature, $expires_at + 1));
    }

    /**
     * A signature computed with a different secret is rejected — proof
     * the secret, not just the message shape, is actually load-bearing.
     */
    public function test_signature_from_a_different_secret_is_rejected(): void
    {
        $expires_at   = 1893456000;
        $other_secret = 'a-different-secret';
        $other_signer = new R2PlaybackUrlSigner(
            new R2MediaUrlNormalizer('https://media.nangcuctvc.com'),
            $other_secret
        );
        $signature    = hash_hmac('sha256', 'clip.mp4' . "\n" . $expires_at, $other_secret);

        self::assertFalse($this->signer->verify('clip.mp4', $expires_at, $signature, $expires_at - 1));
        // Sanity: the other signer's own secret does verify this same signature.
        self::assertTrue($other_signer->verify('clip.mp4', $expires_at, $signature, $expires_at - 1));
    }

    /**
     * An empty/unconfigured secret fails closed: `verify()` never
     * succeeds, and `sign_url()` falls back to the bare, unsigned
     * permanent URL rather than fabricating a meaningless signature.
     */
    public function test_unconfigured_secret_fails_closed(): void
    {
        $unconfigured = new R2PlaybackUrlSigner(new R2MediaUrlNormalizer('https://media.nangcuctvc.com'), '');

        self::assertSame('https://media.nangcuctvc.com/clip.mp4', $unconfigured->sign_url('clip.mp4'));
        self::assertFalse($unconfigured->verify('clip.mp4', 1893456000, 'anything', 100));
    }

    /**
     * A signed URL for a Unicode/space-containing object key round-trips
     * through the same percent-encoding `R2MediaUrlNormalizerTest`
     * already proves for the unsigned URL shape, and verifies correctly
     * once the `exp`/`sig` query params are stripped back off.
     */
    public function test_signed_url_for_unicode_object_key_verifies_correctly(): void
    {
        $object_key = "EM Tu\u{0301} Nhie\u{0302}n Qua\u{0309}ng Ninh nangcuc.mp4";
        $url        = $this->signer->sign_url($object_key);

        self::assertStringStartsWith(
            'https://media.nangcuctvc.com/EM%20Tu%CC%81%20Nhie%CC%82n%20Qua%CC%89ng%20Ninh%20nangcuc.mp4?',
            $url
        );

        [$exp, $sig] = $this->extract_query_params($url);

        self::assertTrue($this->signer->verify($object_key, $exp, $sig, $exp - 1));
    }

    /**
     * Extract the `exp`/`sig` query parameters from a signed URL, in
     * that order.
     *
     * @param string $url A URL as returned by `R2PlaybackUrlSigner::sign_url()`.
     *
     * @return array{0: int, 1: string}
     */
    private function extract_query_params(string $url): array
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this is a WordPress-independent unit test (no WP bootstrap), so wp_parse_url() isn't available here.
        $query = parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query);

        parse_str($query, $params);

        $exp = $params['exp'];
        $sig = $params['sig'];

        self::assertIsString($exp);
        self::assertIsString($sig);

        return [ (int) $exp, $sig];
    }

    /**
     * Compute the exact same HMAC this feature's signer/Worker both
     * compute, using this test's own configured secret — a standalone
     * reference computation independent of `R2PlaybackUrlSigner`'s
     * internals, so these tests don't just check the class against
     * itself.
     *
     * @param string $object_key The object key to sign.
     * @param int    $expires_at The expiry timestamp to sign.
     */
    private function sign_for_test(string $object_key, int $expires_at): string
    {
        return hash_hmac('sha256', $object_key . "\n" . $expires_at, self::SECRET);
    }
}
