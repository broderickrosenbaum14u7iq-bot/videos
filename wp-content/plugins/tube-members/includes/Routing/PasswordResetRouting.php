<?php
/**
 * Rewrite/template_include routing for the password-reset landing page.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Routing;

/**
 * Rewrite/`template_include` routing for `/dat-lai-mat-khau/`, the page
 * a password-reset email's `login`/`key` query params land on -- same
 * rewrite/query-var/`template_include` shape as {@see EmailVerificationRouting},
 * per that class's own docblock, so this route's markup stays fully
 * owned by this plugin the same way. `login`/`key` (not this project's
 * usual `uid`/`token`) deliberately matches the exact param names
 * {@see check_password_reset_key()} itself expects, since this route's
 * only job is to collect them from the URL and a new-password form,
 * then hand them straight to that WordPress-native function -- no
 * translation layer between this route and core's own reset-key API.
 *
 * Works for both logged-in and logged-out visitors, the same posture
 * {@see EmailVerificationRouting} already takes: token possession, not
 * session state, is what authorizes a reset.
 */
final class PasswordResetRouting
{
    private const SLUG = 'dat-lai-mat-khau';

    private const QUERY_VAR = 'tube_members_reset_password';

    /**
     * The reset link's URL for one login/key pair, e.g.
     * `https://example.com/dat-lai-mat-khau/?login=alice&key=<raw>`. The
     * raw key appears ONLY here (put in the emailed link) and in the
     * visitor's own inbox -- WordPress core stores only its own hash of
     * it (`wp_users.user_activation_key`), the same "never store the raw
     * value" posture this plugin's own email-verification tokens already
     * follow.
     *
     * @param string $login The account's user_login.
     * @param string $key   The raw reset key {@see get_password_reset_key()} returned.
     */
    public static function url(string $login, string $key): string
    {
        return add_query_arg(
            [
                'login' => rawurlencode($login),
                'key'   => rawurlencode($key),
            ],
            home_url('/' . self::SLUG . '/')
        );
    }

    /**
     * Register this route's rewrite rule. Called on `init`, and
     * synchronously during plugin activation.
     */
    public function add_rewrite_rules(): void
    {
        add_rewrite_rule('^' . self::SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
    }

    /**
     * `query_vars` filter callback.
     *
     * @param string[] $vars The current public query vars.
     *
     * @return string[]
     */
    public function register_query_var(array $vars): array
    {
        $vars[] = self::QUERY_VAR;

        return $vars;
    }

    /**
     * `template_include` filter callback: when this route matched, hand
     * off to this plugin's own reset-password page template. Unlike
     * {@see EmailVerificationRouting::route_template()}, no action is
     * taken here yet -- the landing page shows a new-password form,
     * validated live by `check_password_reset_key()` only when that
     * form actually submits (`POST /tube/v1/auth/reset-password`), not
     * merely by visiting the link (a link-scanner/email-preview-bot
     * prefetching this URL must not burn a legitimate visitor's own
     * one-time key -- `check_password_reset_key()` does not consume the
     * key on read, only `reset_password()` does, so this is safe either
     * way, but the page itself still does no key validation of its own
     * on load, deferring entirely to the REST endpoint).
     *
     * @param string $template The template WordPress would otherwise use.
     */
    public function route_template(string $template): string
    {
        if ('1' !== \Tube_Members\Support\Params::string(get_query_var(self::QUERY_VAR))) {
            return $template;
        }

        status_header(200);

        return TUBE_MEMBERS_DIR . '/includes/Render/templates/password-reset-page.php';
    }
}
