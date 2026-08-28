<?php
/**
 * Server-side Google OAuth 2.0 / OpenID Connect client.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\OAuth;

use Tube_Members\Support\Params;

/**
 * Server-side Google OAuth 2.0 / OpenID Connect client, per Phase 6.
 *
 * The Client Secret is only ever read from `wp_options` (set via
 * `GoogleSettingsScreen`) and only ever used in server-to-server HTTP
 * requests (`wp_remote_post()`/`wp_remote_get()`) — it is never
 * constructed from or exposed to any frontend request.
 *
 * Deliberately does not implement its own ID-token (JWT) signature
 * verification: the authorization-code exchange with Google's token
 * endpoint is itself a confidential, TLS-protected, client-secret-
 * authenticated server-to-server call, so the `access_token` it returns
 * is already trustworthy — this class uses it to call Google's own
 * `userinfo` endpoint for the verified profile rather than re-deriving
 * the same trust decision from a hand-rolled JWT verifier. This is a
 * standard, correct OAuth2/OIDC integration shape, not an invented API
 * (Phase 19/Phase 6's "Do NOT invent an API" applies to the video-player
 * bridge, not to this well-documented Google endpoint pair).
 */
final class GoogleOAuthClient
{
    private const AUTH_ENDPOINT     = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT    = 'https://oauth2.googleapis.com/token';
    private const USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v3/userinfo';

    /**
     * Private: use {@see self::from_options()}.
     *
     * @param string $client_id     The configured Google OAuth Client ID.
     * @param string $client_secret The configured Google OAuth Client Secret.
     */
    private function __construct(private readonly string $client_id, private readonly string $client_secret)
    {
    }

    /**
     * Build a client from the configured `wp_options` (Client ID/
     * Secret), or null if either is absent — the exact signal
     * `Tube_Members\Plugin::google_oauth_client()` uses to decide
     * whether to register the `/auth/google/*` routes at all, and that
     * `HeaderAccountRenderer` uses to decide whether to render the
     * Google button (Phase 6: "If credentials are absent: Google button
     * may be hidden or clearly unavailable").
     */
    public static function from_options(): ?self
    {
        $raw_client_id     = Params::string(get_option('tube_members_google_client_id', ''));
        $raw_client_secret = Params::string(get_option('tube_members_google_client_secret', ''));
        $client_id         = trim($raw_client_id);
        $client_secret     = trim($raw_client_secret);

        if ('' === $client_id || '' === $client_secret) {
            return null;
        }

        return new self($client_id, $client_secret);
    }

    /**
     * The fixed redirect URI this project registers with Google —
     * displayed verbatim in `GoogleSettingsScreen` so the site admin can
     * paste it into Google Cloud Console's "Authorized redirect URIs".
     */
    public static function redirect_uri(): string
    {
        return rest_url('tube/v1/auth/google/callback');
    }

    /**
     * The URL to send the visitor's browser to, to start a real Google
     * sign-in. $state must be a fresh, unguessable, single-use value the
     * caller has already recorded server-side (Phase 6: "validate
     * state... Prevent OAuth CSRF").
     *
     * @param string $state A fresh, unguessable, single-use, server-recorded value.
     */
    public function authorization_url(string $state): string
    {
        return self::AUTH_ENDPOINT . '?' . http_build_query(
            [
                'client_id'     => $this->client_id,
                'redirect_uri'  => self::redirect_uri(),
                'response_type' => 'code',
                'scope'         => 'openid email profile',
                'state'         => $state,
                'prompt'        => 'select_account',
            ]
        );
    }

    /**
     * Exchange a real authorization code for the signed-in Google
     * account's verified profile.
     *
     * @param string $code The real authorization code Google issued.
     *
     * @return array{sub: string, email: string, email_verified: bool, name: string, picture: string}
     *
     * @throws GoogleOAuthException On any network/HTTP/malformed-response failure.
     */
    public function exchange_code_for_profile(string $code): array
    {
        $token_response = wp_remote_post(
            self::TOKEN_ENDPOINT,
            [
                'timeout' => 10,
                'body'    => [
                    'code'          => $code,
                    'client_id'     => $this->client_id,
                    'client_secret' => $this->client_secret,
                    'redirect_uri'  => self::redirect_uri(),
                    'grant_type'    => 'authorization_code',
                ],
            ]
        );

        if (is_wp_error($token_response) || 200 !== wp_remote_retrieve_response_code($token_response)) {
            throw new GoogleOAuthException('Google token exchange failed.');
        }

        $token_body   = json_decode(wp_remote_retrieve_body($token_response), true);
        $access_token = is_array($token_body) ? ($token_body['access_token'] ?? null) : null;

        if (! is_string($access_token) || '' === $access_token) {
            throw new GoogleOAuthException('Google token exchange returned no access token.');
        }

        $userinfo_response = wp_remote_get(
            self::USERINFO_ENDPOINT,
            [
                'timeout' => 10,
                'headers' => ['Authorization' => 'Bearer ' . $access_token],
            ]
        );

        if (is_wp_error($userinfo_response) || 200 !== wp_remote_retrieve_response_code($userinfo_response)) {
            throw new GoogleOAuthException('Google userinfo request failed.');
        }

        $userinfo = json_decode(wp_remote_retrieve_body($userinfo_response), true);

        if (! is_array($userinfo) || ! isset($userinfo['sub'], $userinfo['email'])) {
            throw new GoogleOAuthException('Google userinfo response was malformed.');
        }

        return [
            'sub'            => Params::string($userinfo['sub']),
            'email'          => Params::string($userinfo['email']),
            'email_verified' => Params::bool($userinfo['email_verified'] ?? false),
            'name'           => Params::string($userinfo['name'] ?? ''),
            'picture'        => Params::string($userinfo['picture'] ?? ''),
        ];
    }
}
