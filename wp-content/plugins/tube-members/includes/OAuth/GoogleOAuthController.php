<?php
/**
 * `GET /tube/v1/auth/google/start` and `/callback`.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\OAuth;

use Tube_Members\Auth\AuthSessionService;
use Tube_Members\Email\EmailVerificationService;
use Tube_Members\Profile\AvatarService;
use Tube_Members\Support\Params;
use Tube_Members\Support\UniqueLogin;
use WP_Error;
use WP_REST_Request;
use WP_User;

/**
 * `GET /tube/v1/auth/google/start` (redirects the browser to Google) and
 * `GET /tube/v1/auth/google/callback` (Google redirects back here), per
 * Phase 6. Both are real browser navigations (not `fetch()` calls) —
 * OAuth's redirect-based flow requires the top-level browser context, so
 * unlike every other endpoint in this plugin, these two return raw HTTP
 * redirects rather than `WP_REST_Response` JSON.
 */
final class GoogleOAuthController
{
    /**
     * The transient key prefix a single-use OAuth state value is recorded under.
     */
    private const STATE_TRANSIENT_PREFIX = 'tube_members_oauth_state_';

    /**
     * How long a state value remains valid — long enough for a real
     * visitor to complete Google's own sign-in UI, short enough to keep
     * a stale, unused state from lingering.
     */
    private const STATE_TTL_SECONDS = 600;

    /**
     * The usermeta key a linked Google account's subject identifier is stored under.
     */
    private const GOOGLE_SUB_META = 'tube_members_google_sub';

    /**
     * Construct around the collaborators this controller authenticates through.
     *
     * @param GoogleOAuthClient        $client              Talks to Google.
     * @param AuthSessionService       $auth_session        Establishes the real WordPress session once identity is verified.
     * @param EmailVerificationService $email_verification  Marks the account verified — Google's own `email_verified`
     *                                                        claim is already checked true before this is ever reached
     *                                                        (Phase 21: "should not require redundant verification").
     */
    public function __construct(
        private readonly GoogleOAuthClient $client,
        private readonly AuthSessionService $auth_session,
        private readonly EmailVerificationService $email_verification
    ) {
    }

    /**
     * Redirects the browser to Google's consent screen, first recording
     * a fresh single-use `state` value server-side (Phase 6: "validate
     * state... Prevent OAuth CSRF") alongside the page to return to.
     *
     * @param WP_REST_Request $request The incoming request; `return_to` is the only param read.
     */
    public function start(WP_REST_Request $request): void
    {
        $raw_return_to = Params::string($request->get_param('return_to'));
        $return_to     = $this->safe_return_to($raw_return_to);
        $state         = wp_generate_password(32, false, false);

        set_transient(self::STATE_TRANSIENT_PREFIX . $state, $return_to, self::STATE_TTL_SECONDS);

        $authorization_url = $this->client->authorization_url($state);
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- destination is Google's own fixed OAuth endpoint, not user input.
        wp_redirect($authorization_url);
        exit;
    }

    /**
     * Google's redirect back. Validates `state` against the transient
     * recorded by {@see self::start()} (one-time use: the transient is
     * deleted immediately on read, so a replayed callback URL fails),
     * exchanges the code, finds-or-creates the WordPress account, logs
     * it in, then returns the browser to the original page.
     *
     * Fails open to $return_to (never to a dead page) on every error
     * path — an invalid state, a Google-reported error, a failed
     * exchange, or an unverified email all simply return the visitor to
     * where they started, still logged out, able to fall back to
     * email/password.
     *
     * @param WP_REST_Request $request The incoming request.
     */
    public function callback(WP_REST_Request $request): void
    {
        $state = Params::string($request->get_param('state'));
        $code  = Params::string($request->get_param('code'));
        $error = Params::string($request->get_param('error'));

        $transient_key   = self::STATE_TRANSIENT_PREFIX . $state;
        $recorded_return = '' !== $state ? get_transient($transient_key) : false;

        if (false === $recorded_return) {
            wp_die(
                esc_html__('Yêu cầu đăng nhập Google không hợp lệ hoặc đã hết hạn.', 'tube-members'),
                esc_html__('Lỗi đăng nhập', 'tube-members'),
                ['response' => 400]
            );
        }

        delete_transient($transient_key);

        $return_to = is_string($recorded_return) ? $recorded_return : home_url('/');

        if ('' !== $error || '' === $code) {
            $this->redirect_to($return_to);
        }

        try {
            $profile = $this->client->exchange_code_for_profile($code);
        } catch (GoogleOAuthException $exception) {
            $this->redirect_to($return_to);

            return;
        }

        if (! $profile['email_verified'] || '' === $profile['email']) {
            $this->redirect_to($return_to);

            return;
        }

        $user = $this->find_or_create_user($profile);

        if (null === $user) {
            $this->redirect_to($return_to);

            return;
        }

        $this->auth_session->log_in($user, true);
        $this->redirect_to($return_to);
    }

    /**
     * Find an existing account already linked to this Google subject,
     * else an existing WordPress account whose email exactly matches
     * (linking it), else create a brand-new, safe frontend member
     * account (Phase 6). $profile['email_verified'] is guaranteed true
     * by the caller before this is ever invoked.
     *
     * @param array{sub: string, email: string, email_verified: bool, name: string, picture: string} $profile The verified Google profile.
     */
    private function find_or_create_user(array $profile): ?WP_User
    {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one-time OAuth lookup, not a per-page-load query; no higher-traffic path reaches this.
        $linked = get_users(
            [
                'meta_key'   => self::GOOGLE_SUB_META,
                'meta_value' => $profile['sub'],
                'number'     => 1,
            ]
        );

        /** @var list<WP_User> $linked */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        if ([] !== $linked) {
            return $linked[0];
        }

        $by_email = get_user_by('email', $profile['email']);

        if ($by_email instanceof WP_User) {
            // Security-critical (2026-08-28, P0 CRIT-1 fix): checked
            // BEFORE linking/marking-verified below, against the
            // account's state as it was walking in the door. Matching
            // on email alone is not proof of ownership by itself -- an
            // attacker can pre-register any email address with a
            // password of their choosing and never verify it. Linking
            // Google here (an exact, `email_verified` email match) IS
            // sufficient proof that the person completing this OAuth
            // flow owns the mailbox right now, but it is NOT proof that
            // they, rather than an earlier pre-registerer, are the one
            // who should still control that account's original
            // password. The old code linked and marked-verified
            // unconditionally, leaving that original password valid
            // forever -- a standing backdoor for whoever registered
            // first. An already-*verified* account, by contrast, already
            // proved ownership through a real prior step (an email
            // link click or an earlier Google link), so its password is
            // left untouched here -- this only closes the specific
            // pre-registration window, it does not add friction to
            // every Google sign-in.
            $was_already_verified = $this->email_verification->is_verified($by_email);

            update_user_meta($by_email->ID, self::GOOGLE_SUB_META, $profile['sub']);
            $this->store_google_avatar($by_email->ID, $profile['picture']);
            $this->email_verification->mark_verified_from_trusted_provider($by_email->ID);

            if (! $was_already_verified) {
                // Rotates to a random, nobody-knows-it password -- the
                // exact same value shape a brand-new OAuth-created
                // account already gets a few lines below. This also
                // destroys every existing session for the account (a
                // documented side effect of wp_set_password() itself),
                // so a pre-registerer's live session is force-logged-out
                // too, not just their password invalidated. The real
                // owner is unaffected: they're about to be logged in
                // fresh via Google regardless, and can set a real
                // password anytime afterward through the account page's
                // existing password-change flow.
                wp_set_password(wp_generate_password(32, true, true), $by_email->ID);
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate security-audit logging for a real account-takeover-prevention event, not debug output.
                error_log(
                    sprintf(
                        '[tube-members] OAuth-linked previously-unverified account #%d and invalidated its prior password (closing a pre-registration account-takeover window).',
                        $by_email->ID
                    )
                );
            }

            return $by_email;
        }

        $display_name = '' !== $profile['name'] ? $profile['name'] : (string) explode('@', $profile['email'])[0];
        $user_login   = UniqueLogin::generate($profile['email'], $display_name);

        $user_id = wp_insert_user(
            [
                'user_login'   => $user_login,
                'user_email'   => $profile['email'],
                'user_pass'    => wp_generate_password(32, true, true),
                'display_name' => $display_name,
                'nickname'     => $display_name,
                'role'         => 'subscriber',
            ]
        );

        if ($user_id instanceof WP_Error) {
            return null;
        }

        $new_user_id = (int) $user_id;

        update_user_meta($new_user_id, self::GOOGLE_SUB_META, $profile['sub']);
        $this->store_google_avatar($new_user_id, $profile['picture']);
        $this->email_verification->mark_verified_from_trusted_provider($new_user_id);

        $user = get_userdata($new_user_id);

        return $user instanceof WP_User ? $user : null;
    }

    /**
     * Record a Google avatar URL for a linked/created account, if Google supplied one.
     *
     * @param int    $user_id     The member account.
     * @param string $picture_url The Google-supplied avatar URL, or '' if none.
     */
    private function store_google_avatar(int $user_id, string $picture_url): void
    {
        if ('' !== $picture_url) {
            update_user_meta($user_id, AvatarService::META_GOOGLE_AVATAR_URL, $picture_url);
        }
    }

    /**
     * Only ever allow returning to a same-host URL — an attacker-
     * controlled `return_to` on `/auth/google/start` must never become
     * an open redirect (Phase 6).
     *
     * @param string $return_to The visitor-supplied return destination.
     */
    private function safe_return_to(string $return_to): string
    {
        if ('' === $return_to) {
            return home_url('/');
        }

        $target_host = wp_parse_url($return_to, PHP_URL_HOST);
        $home_host   = wp_parse_url(home_url('/'), PHP_URL_HOST);

        if (null !== $target_host && $target_host !== $home_host) {
            return home_url('/');
        }

        return $return_to;
    }

    /**
     * Issue the final redirect back to the original page and stop execution.
     *
     * @param string $return_to The already-validated destination.
     */
    private function redirect_to(string $return_to): void
    {
        wp_safe_redirect($return_to);
        exit;
    }
}
