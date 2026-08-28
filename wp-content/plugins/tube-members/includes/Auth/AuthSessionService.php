<?php
/**
 * The one real WordPress-session entry point every auth flow logs in through.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Auth;

use Tube_Core\Plugin as Tube_Core_Plugin;
use Tube_Core\WatchHistory\VisitorToken;
use Tube_Members\Support\Params;
use WP_User;

/**
 * The one real WordPress-session entry point every auth flow (email/
 * password login, auto-login after registration, Google OAuth) logs a
 * member in through, per `Tube_Members\Plugin`'s own docblock. Sets the
 * real `wp_set_auth_cookie()` — no parallel session mechanism.
 *
 * Also the single place a just-authenticated guest's like/save history
 * is merged into their account (Phase 26/27): every caller of
 * {@see self::log_in()} gets this for free, so there is exactly one
 * code path to keep correct rather than one per auth method.
 */
final class AuthSessionService
{
    /**
     * Log $user in: set the real WordPress auth cookie and current
     * user, fire the standard `wp_login` action (so any other plugin/
     * core behavior that expects it — e.g. "last login" trackers — still
     * runs, the same as a normal `wp-login.php` sign-in would trigger),
     * then merge any guest like/save history into this account.
     *
     * @param WP_User $user     The member being logged in.
     * @param bool    $remember Whether the auth cookie should persist past the browser session.
     */
    public function log_in(WP_User $user, bool $remember = false): void
    {
        // wp_set_auth_cookie() only calls setcookie() -- it never
        // updates PHP's own $_COOKIE superglobal for THIS request. Left
        // alone, that means wp_get_session_token()/wp_create_nonce('wp_rest')
        // called later in this same request (by RegistrationController/
        // LoginController, to hand the frontend a nonce it can use
        // immediately without a page reload — Phase 34) would still see
        // the OLD (usually empty, pre-login) session token and mint a
        // nonce that will never validate once the browser's real new
        // cookie is what every SUBSEQUENT request actually carries.
        // Capturing the real cookie value via its own action and
        // patching $_COOKIE here makes every nonce/session read for the
        // rest of this request agree with what the browser now has.
        $logged_in_cookie = null;

        $capture = static function (string $cookie) use (&$logged_in_cookie): void {
            $logged_in_cookie = $cookie;
        };

        add_action('set_logged_in_cookie', $capture);

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, $remember);

        remove_action('set_logged_in_cookie', $capture);

        if (null !== $logged_in_cookie && defined('LOGGED_IN_COOKIE')) {
            $cookie_name             = Params::string(LOGGED_IN_COOKIE);
            $_COOKIE[ $cookie_name ] = $logged_in_cookie;
        }

        /**
         * This mirrors what `wp_signon()` fires internally, kept
         * separate because none of this plugin's auth flows call
         * `wp_signon()` itself (registration and Google OAuth have no
         * password to hand it, and the email/password login path uses
         * `wp_authenticate()` directly so it can return Vietnamese
         * errors instead of WP_Error's English ones).
         */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- this is WordPress core's own 'wp_login' action (the same one wp_signon() fires internally), invoked deliberately with its real, unprefixed core name, never a hook this plugin declares itself.
        do_action('wp_login', $user->user_login, $user);

        $this->merge_guest_engagement($user);
    }

    /**
     * Log the current visitor out of their real WordPress session.
     */
    public function log_out(): void
    {
        wp_logout();
    }

    /**
     * The current visitor's account, or null when not logged in.
     */
    public function current_member(): ?WP_User
    {
        if (! is_user_logged_in()) {
            return null;
        }

        $user = wp_get_current_user();

        return 0 === $user->ID ? null : $user;
    }

    /**
     * Merge this browser's existing guest like/save history into the
     * account that just logged in, if it has any (Phase 26/27) — reads
     * the guest's `tube_visitor` cookie without creating one for a
     * visitor who never had guest engagement to merge.
     *
     * @param WP_User $user The member account that just logged in.
     */
    private function merge_guest_engagement(WP_User $user): void
    {
        if (! class_exists(Tube_Core_Plugin::class)) {
            return;
        }

        $visitor_token = (new VisitorToken())->current();

        if (null === $visitor_token) {
            return;
        }

        Tube_Core_Plugin::instance()->merge_guest_engagement_into_user($visitor_token, $user->ID);
    }
}
