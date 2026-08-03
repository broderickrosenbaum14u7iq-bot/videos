<?php
/**
 * Real VideoProviderInterface implementation, backed by Cloudflare Stream.
 *
 * @package Tube_Player
 */

declare(strict_types=1);

namespace Tube_Player\Video\Cloudflare;

use RuntimeException;
use Tube_Player\Video\VideoProviderInterface;

/**
 * Real VideoProviderInterface implementation, backed by Cloudflare
 * Stream, per ARCHITECTURE.md §12 Phase 6.
 *
 * Every URL is built from `https://customer-{$customer_code}.cloudflarestream.com/`
 * (Cloudflare Stream's standard per-account delivery domain) plus either
 * the plain Stream UID (unsigned — the default) or, when a signing key
 * is configured, a signed token in its place — Cloudflare Stream's
 * signed-URL scheme puts a JWT where the UID would otherwise go, valid
 * for every endpoint under that UID (iframe, thumbnail, manifest).
 * Unsigned is the default per this phase's explicit instruction: leave
 * `$signing_key_id`/`$signing_key_pem` null unless signed URLs are
 * actually configured.
 *
 * The signed-token JWT itself (header `{"alg":"RS256","kid":...}`,
 * payload `{"sub":<uid>,"kid":...,"exp":...}`, RS256-signed) is built
 * inline rather than via a third-party JWT library or a separate signer
 * class — it is three fixed fields and one `openssl_sign()` call, not
 * enough real logic to justify either.
 *
 * No WordPress functions, no network calls — fully unit-tested directly,
 * including the signed path (verified by decoding the returned token
 * with the matching public key).
 */
final class CloudflareStreamProvider implements VideoProviderInterface
{
    /**
     * Construct around the account's Stream customer code and, when
     * signed URLs are enabled, the signing key.
     *
     * @param string      $customer_code          The Cloudflare Stream customer code (the subdomain segment).
     * @param string|null $signing_key_id         The Stream signing key's ID (JWT `kid`), null for unsigned (default).
     * @param string|null $signing_key_pem        The RSA private key (PEM), required alongside `$signing_key_id`.
     * @param int         $signed_url_ttl_seconds How long a signed token stays valid, from the moment it's signed.
     */
    public function __construct(
        private readonly string $customer_code,
        private readonly ?string $signing_key_id = null,
        private readonly ?string $signing_key_pem = null,
        private readonly int $signed_url_ttl_seconds = 3600
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param string $cf_stream_uid The Cloudflare Stream UID.
     */
    public function embed_url(string $cf_stream_uid): string
    {
        return $this->base_url($cf_stream_uid) . '/iframe';
    }

    /**
     * {@inheritDoc}
     *
     * @param string $cf_stream_uid          The Cloudflare Stream UID.
     * @param int    $thumbnail_time_seconds Offset into the video to extract the thumbnail frame from.
     * @param int    $width                  Requested pixel width.
     * @param int    $height                 Requested pixel height.
     */
    public function thumbnail_url(string $cf_stream_uid, int $thumbnail_time_seconds, int $width, int $height): string
    {
        $query = http_build_query(
            [
                'time'   => $thumbnail_time_seconds . 's',
                'width'  => $width,
                'height' => $height,
                'fit'    => 'crop',
            ]
        );

        return $this->base_url($cf_stream_uid) . '/thumbnails/thumbnail.jpg?' . $query;
    }

    /**
     * The shared `.../{path segment}` prefix every Cloudflare Stream URL
     * is built from — the plain UID (unsigned) or a signed token in its
     * place, per this instance's configuration.
     *
     * @param string $cf_stream_uid The Cloudflare Stream UID.
     */
    private function base_url(string $cf_stream_uid): string
    {
        $path_segment = (null === $this->signing_key_id || null === $this->signing_key_pem)
            ? $cf_stream_uid
            : $this->sign($cf_stream_uid);

        return "https://customer-{$this->customer_code}.cloudflarestream.com/{$path_segment}";
    }

    /**
     * Sign a Cloudflare Stream token for one UID.
     *
     * @param string $cf_stream_uid The Cloudflare Stream UID to sign a token for.
     *
     * @throws RuntimeException If the configured signing key is not a valid RSA private key, or signing fails.
     */
    private function sign(string $cf_stream_uid): string
    {
        $header = [
            'alg' => 'RS256',
            'kid' => $this->signing_key_id,
        ];

        $payload = [
            'sub' => $cf_stream_uid,
            'kid' => $this->signing_key_id,
            'exp' => time() + $this->signed_url_ttl_seconds,
        ];

        $signing_input   = self::base64url_json_encode($header) . '.' . self::base64url_json_encode($payload);
        $signing_key_pem = (string) $this->signing_key_pem;
        $private_key     = openssl_pkey_get_private($signing_key_pem);

        if (false === $private_key) {
            throw new RuntimeException('The configured Cloudflare Stream signing key is not a valid RSA private key.');
        }

        $signature = '';
        $signed    = openssl_sign($signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256);

        // openssl_sign()'s $signature is an out-by-reference parameter;
        // PHPStan's stub doesn't narrow its type back to string once the
        // call returns, so is_string() is checked genuinely, not assumed.
        if (! $signed || ! is_string($signature)) {
            throw new RuntimeException('Failed to sign a Cloudflare Stream token.');
        }

        return $signing_input . '.' . self::base64url_encode($signature);
    }

    /**
     * JSON-encode and base64url-encode one JWT segment.
     *
     * @param array<string, int|string|null> $value The segment's fields.
     *
     * @throws RuntimeException If JSON-encoding fails.
     */
    private static function base64url_json_encode(array $value): string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- this class is deliberately WordPress-independent (unit-tested without WordPress loaded), the same reasoning Tube_Core\Stream\WebhookSignatureVerifier documents for using only plain PHP functions.
        $encoded = json_encode($value);

        if (false === $encoded) {
            throw new RuntimeException('Failed to JSON-encode a Cloudflare Stream token segment.');
        }

        return self::base64url_encode($encoded);
    }

    /**
     * Base64url-encode (RFC 4648 §5) a string — standard JWT segment encoding.
     *
     * @param string $data The raw bytes to encode.
     */
    private static function base64url_encode(string $data): string
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT segment encoding (RFC 4648 §5), not obfuscation; the resulting token is the whole point of this method, not a way to hide it.
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
