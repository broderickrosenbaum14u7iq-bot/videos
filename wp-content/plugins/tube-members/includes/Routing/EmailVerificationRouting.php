<?php
/**
 * Rewrite/template_include routing for the email-verification landing page.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Routing;

use Tube_Members\Email\EmailVerificationService;
use Tube_Members\Email\VerificationEmailSender;
use Tube_Members\Support\Params;

/**
 * Rewrite/`template_include` routing for `/xac-thuc-email/`, the page a
 * verification link's `uid`/`token` query params land on — same
 * rewrite/query-var/`template_include` shape as {@see AccountRouting},
 * per that class's own docblock, so this route's markup/logic stays
 * fully owned by this plugin the same way.
 *
 * Unlike {@see AccountRouting}, this route works for BOTH logged-in and
 * logged-out visitors (Phase 8: "If they are logged out: verification
 * should STILL work") — token possession, not session state, is what
 * authorizes the verification itself.
 */
final class EmailVerificationRouting
{
    private const SLUG = 'xac-thuc-email';

    private const QUERY_VAR = 'tube_members_verify_email';

    /**
     * The verification link's URL for one user/token pair, e.g.
     * `https://example.com/xac-thuc-email/?uid=123&token=<raw>`. The raw
     * token appears ONLY here (put in the emailed link) and in the
     * visitor's own inbox — never logged, stored, or exposed anywhere
     * else (Phase 2/7).
     *
     * @param int    $user_id  The account being verified.
     * @param string $raw_token The one-time raw token.
     */
    public static function url(int $user_id, string $raw_token): string
    {
        return add_query_arg(
            [
                'uid'   => $user_id,
                'token' => $raw_token,
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
     * `template_include` filter callback: when this route matched, run
     * the actual verification (reading `uid`/`token` straight from
     * `$_GET` — they are never part of the rewrite pattern itself, just
     * ordinary query-string params on this matched slug) and hand off
     * to this plugin's own result template.
     *
     * `noindex, follow` is emitted by `tube-seo`'s own `SeoHead::resolve()`
     * (Phase 33), which reads this route's own query var
     * (`tube_members_verify_email`) the exact same bare, no-compile-time-
     * dependency way it already does for `AccountRouting`'s
     * `tube_members_account` — this class does not (and, per this
     * project's own architecture, cannot: the theme never calls core's
     * `wp_robots()` hook at all, `SeoHead` is the only real robots-meta
     * output path) set that tag itself.
     *
     * @param string $template The template WordPress would otherwise use.
     */
    public function route_template(string $template): string
    {
        if ('1' !== Params::string(get_query_var(self::QUERY_VAR))) {
            return $template;
        }

        // A verification link's uid/token pair IS the authorization --
        // there is no prior form submission to carry a nonce from, the
        // same posture wp-login.php's own key= reset-password links use.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $user_id = Params::int($_GET['uid'] ?? null);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        $token = Params::string(wp_unslash($_GET['token'] ?? ''));

        $service = new EmailVerificationService(new VerificationEmailSender());
        $result  = $service->verify($user_id, $token);

        status_header(200);

        set_query_var('tube_members_verification_result', $result);

        return TUBE_MEMBERS_DIR . '/includes/Render/templates/email-verification-page.php';
    }
}
