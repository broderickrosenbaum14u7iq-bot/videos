<?php
/**
 * Unit tests for CloudflareStreamProvider.
 *
 * @package Tube_Player
 */

declare(strict_types=1);

namespace Tube_Player\Tests\Unit\Video\Cloudflare;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tube_Player\Video\Cloudflare\CloudflareStreamProvider;

/**
 * Exercises CloudflareStreamProvider's unsigned URL construction
 * directly, and its signed-mode JWT by actually decoding and
 * cryptographically verifying the token it produces against a
 * throwaway test RSA keypair — not just checking the URL shape.
 */
final class CloudflareStreamProviderTest extends TestCase
{
    /**
     * A throwaway 2048-bit RSA keypair, generated only for this test —
     * never used against a real Cloudflare account.
     */
    private const TEST_PRIVATE_KEY_PEM = <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQCrY6CaPycoEvCR
        X0t3AU91H4vElSy9+OyNNTV3PA3iy3vl4p+6R4civtTzjjIl4X4fZtd2+vnmQLZa
        8CAcfeSYVdol7t3rthrPKBipKL0wtZAkmsUqS4PfsOtF2Yth90LRVHsnpavIK6va
        63iUYo1+27BcXYb+YYL8Gf5xPD1eabo7DQDN3FsScDKDEOz68+blMFpzCLy0iG4u
        lOqlAja62bKB4U0Hf4EBxs60CqFTsnoPE3Y4c4Yq9Ejn6btNY1f/EDxBHj5kuZuH
        6WriXERrMX5fa4q34r6xVnTjrrB/XUpk0i9H/aXV39gBNHhQPD9H8AYPt/aSUpT9
        2hzXa1vPAgMBAAECggEACIaAKqwRipDGnrSa0GSkSzMyhKjW/Oww+CU5b6DxH+L3
        WswXHfEI69WKSkM8g13gJDe9aBG79JQrfgKL1l78zAcZPuHO8DayxrM75s47+vxN
        G0UHob735Fysl2Dm6gyeqkMFjUdUcDSb69vit57fYvwSncWZPXYkSd4RJhjVBKkw
        19U4hL6b2eV+q1f0TKBPnHFquj/1onSRxBrkpU/0KkCj4tqc/un4X3safxjhHYYC
        ptLvDSGEn+qNcog/xVm6fihXv9/PvGjFCNwHBdJ50CXcB/gsMzzQYW6dGBFXtkR3
        MSb63ym1/0XCncc23+TF+FxgXwtN7jYUk0/3Xam4IQKBgQDdBTqB8wPhdltq9eul
        UwrMK+vtYxLUVIfWYvVuUjjoRoH8HMLlt+wSNQaBIEaKVhAo4RFy6d5D9XOWKdzU
        bUrgeBu3nUoH8ykxPIJ80dP5hlqX6w8ThVq7Xd8PpDVYbJEKYe5lBc+Bi0s/D2j7
        fq9zV83kj58AoQGOIhAwcOSfywKBgQDGg48/kHhPeq4FXYJI3eO+8bn37OIguaox
        +H0wSuLyj5tkc6+IzPy3PtB7Q7eCOlN2LeIj+ChwRUXaPDCHkwVRh1xWw0ZD7tq+
        +fbNQHk+cAQeOPpzKQdB+MR34k5UBUbz3pi3Pl17OQJjRJOGT9mbedfxfKryDeZT
        E/jUZnDrjQKBgQDShaBtFuSjZPE+uG90Udz/DPb0bmIJDVs1wZy1MGw0ErTNRzf9
        R2r9DLTdWbjXG5LY3UiZhFnJsYofhlBpppCjrsP36UISwHKEC3bfHZ4jFu5Dtgnu
        Nh9uSMOnSmnlh8O/d/hzEU3Nvrg1oKAGrWzBGOlsw8BYAQRSNe/ltCKQcQKBgQCL
        JdiZY7kRbRriI+OZE//57hK/GEqBSeNk15tY6IUgJU11IioeqCFUiTE11gehvySV
        qB/luqMN32DRUnNp9MI7nbg6EPMF1z15tFktEk5qV7ZrQOE9K7ssoSWGKxlgh7pu
        Ys+FUq/QGev0jTMjaIFOHCAj45EnzlpuTYeTyqLIWQKBgCfa1ldSRWVwp8LoZvh9
        gugMmFG/Q4quhm2IzBU/rpHjWW63xMAUy61U3RLtIyvECSbgsBWwRMtZfaa0ITxh
        Q7d39DSOh+dzF4BjjnLnnZF9IlD/Ip2uRHb4Q/zpNhJnpldaHKaM6ZxAa5QjgZRz
        pKApJJ1EP4hxkBMDD3EfdRl+
        -----END PRIVATE KEY-----
        PEM;

    /**
     * The public half of self::TEST_PRIVATE_KEY_PEM, for verifying signatures in these tests.
     */
    private const TEST_PUBLIC_KEY_PEM = <<<'PEM'
        -----BEGIN PUBLIC KEY-----
        MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAq2Ogmj8nKBLwkV9LdwFP
        dR+LxJUsvfjsjTU1dzwN4st75eKfukeHIr7U844yJeF+H2bXdvr55kC2WvAgHH3k
        mFXaJe7d67YazygYqSi9MLWQJJrFKkuD37DrRdmLYfdC0VR7J6WryCur2ut4lGKN
        ftuwXF2G/mGC/Bn+cTw9Xmm6Ow0AzdxbEnAygxDs+vPm5TBacwi8tIhuLpTqpQI2
        utmygeFNB3+BAcbOtAqhU7J6DxN2OHOGKvRI5+m7TWNX/xA8QR4+ZLmbh+lq4lxE
        azF+X2uKt+K+sVZ0466wf11KZNIvR/2l1d/YATR4UDw/R/AGD7f2klKU/doc12tb
        zwIDAQAB
        -----END PUBLIC KEY-----
        PEM;

    /**
     * Unsigned mode uses the plain UID in the embed URL.
     */
    public function test_unsigned_embed_url_uses_the_plain_uid(): void
    {
        $provider = new CloudflareStreamProvider('abc123');

        self::assertSame(
            'https://customer-abc123.cloudflarestream.com/uid-1/iframe',
            $provider->embed_url('uid-1')
        );
    }

    /**
     * Unsigned mode's thumbnail URL includes the plain UID and every requested dimension/offset.
     */
    public function test_unsigned_thumbnail_url_includes_dimensions_and_time(): void
    {
        $provider = new CloudflareStreamProvider('abc123');

        self::assertSame(
            'https://customer-abc123.cloudflarestream.com/uid-1/thumbnails/thumbnail.jpg'
                . '?time=5s&width=320&height=180&fit=crop',
            $provider->thumbnail_url('uid-1', 5, 320, 180)
        );
    }

    /**
     * Signed mode's embed URL carries a genuinely valid, correctly-signed token in place of the UID.
     */
    public function test_signed_embed_url_carries_a_valid_token(): void
    {
        $provider = new CloudflareStreamProvider('abc123', 'key-1', self::TEST_PRIVATE_KEY_PEM, 3600);

        $url = $provider->embed_url('uid-1');

        self::assertMatchesRegularExpression('#^https://customer-abc123\.cloudflarestream\.com/[^/]+/iframe$#', $url);

        $payload = $this->decode_and_verify_token(self::token_from_url($url));

        self::assertSame('uid-1', $payload['sub']);
        self::assertSame('key-1', $payload['kid']);
        self::assertGreaterThan(time(), $payload['exp']);
    }

    /**
     * Signed mode's thumbnail URL carries the same kind of valid token, and keeps its query string.
     */
    public function test_signed_thumbnail_url_carries_a_valid_token_and_keeps_its_query_string(): void
    {
        $provider = new CloudflareStreamProvider('abc123', 'key-1', self::TEST_PRIVATE_KEY_PEM, 3600);

        $url = $provider->thumbnail_url('uid-1', 5, 320, 180);

        self::assertMatchesRegularExpression(
            '#^https://customer-abc123\.cloudflarestream\.com/[^/]+/thumbnails/thumbnail\.jpg\?'
                . 'time=5s&width=320&height=180&fit=crop$#',
            $url
        );

        $token   = self::token_from_url($url, '/thumbnails/');
        $payload = $this->decode_and_verify_token($token);

        self::assertSame('uid-1', $payload['sub']);
    }

    /**
     * The token's `exp` claim reflects the configured TTL, not a fixed default.
     */
    public function test_signed_token_expiry_reflects_the_configured_ttl(): void
    {
        $provider = new CloudflareStreamProvider('abc123', 'key-1', self::TEST_PRIVATE_KEY_PEM, 10);

        $before = time();
        $url    = $provider->embed_url('uid-1');
        $after  = time();

        $payload = $this->decode_and_verify_token(self::token_from_url($url));

        self::assertGreaterThanOrEqual($before + 10, $payload['exp']);
        self::assertLessThanOrEqual($after + 10, $payload['exp']);
    }

    /**
     * An invalid signing key throws rather than silently producing a broken token.
     */
    public function test_invalid_signing_key_throws(): void
    {
        $provider = new CloudflareStreamProvider('abc123', 'key-1', 'not a real PEM');

        $this->expectException(RuntimeException::class);

        $provider->embed_url('uid-1');
    }

    /**
     * Extract the signed token (the path segment in place of the UID) from a built URL.
     *
     * @param string $url    The URL to extract the token from.
     * @param string $suffix The path segment immediately following the token.
     */
    private static function token_from_url(string $url, string $suffix = '/iframe'): string
    {
        $prefix = 'https://customer-abc123.cloudflarestream.com/';

        self::assertStringStartsWith($prefix, $url);

        $remainder = substr($url, strlen($prefix));
        $end       = strpos($remainder, $suffix);

        self::assertIsInt($end);

        return substr($remainder, 0, $end);
    }

    /**
     * Decode a JWT and cryptographically verify its signature against
     * the test public key, returning its payload.
     *
     * @param string $token The JWT to verify.
     *
     * @return array<string, int|string>
     */
    private function decode_and_verify_token(string $token): array
    {
        $parts = explode('.', $token);
        self::assertCount(3, $parts);

        [$header_b64, $payload_b64, $signature_b64] = $parts;

        $public_key = openssl_pkey_get_public(self::TEST_PUBLIC_KEY_PEM);
        self::assertNotFalse($public_key);

        $verified = openssl_verify(
            $header_b64 . '.' . $payload_b64,
            self::base64url_decode($signature_b64),
            $public_key,
            OPENSSL_ALGO_SHA256
        );

        self::assertSame(1, $verified);

        $payload = json_decode(self::base64url_decode($payload_b64), true);
        self::assertIsArray($payload);

        /** @var array<string, int|string> $payload */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        return $payload;
    }

    /**
     * Base64url-decode (RFC 4648 §5) a JWT segment.
     *
     * @param string $data The segment to decode.
     */
    private static function base64url_decode(string $data): string
    {
        $padded  = strtr($data, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a JWT test fixture, not obfuscation.
        $decoded = base64_decode($padded, true);
        self::assertIsString($decoded);

        return $decoded;
    }
}
