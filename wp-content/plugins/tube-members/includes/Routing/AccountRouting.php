<?php
/**
 * Rewrite/template_include routing for the frontend account page.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Routing;

use Tube_Members\Support\Params;

/**
 * Rewrite/`template_include` routing for `/tai-khoan/`, the frontend
 * account page (Phase 9). Same rewrite/query-var/`template_include`
 * mechanics as `Tube_Core\Content\Routing\TermArchiveRouting`, but
 * resolves to a template file living inside THIS plugin
 * (`includes/Render/templates/account-page.php`), never
 * `locate_template()`-d from the theme — per Phase 41's "do not put
 * business logic into the theme", the entire account page (markup and
 * data) is owned by tube-members and stays fully removable with it.
 *
 * Guests are redirected home rather than shown an empty account page —
 * there is no dedicated login page to send them to instead (Phase 4's
 * modal is the only login surface), so the homepage is the safest
 * "somewhere real" destination.
 */
final class AccountRouting
{
    /**
     * The rewrite slug and query var name.
     */
    private const SLUG = 'tai-khoan';

    /**
     * The query var this route's presence is detected through.
     */
    private const QUERY_VAR = 'tube_members_account';

    /**
     * The account page's canonical URL, for redirects and links (header
     * account menu, `MemberRoleGuard`'s wp-admin redirect, etc).
     */
    public static function url(): string
    {
        return home_url('/' . self::SLUG . '/');
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
     * `template_include` filter callback: when this route matched,
     * require a login (redirecting guests home) and hand off to this
     * plugin's own account-page template file — never the theme's.
     *
     * Emits no robots meta tag itself: `tube-seo`'s own `SeoHead::resolve()`
     * reads this exact query var (`tube_members_account`) and returns
     * `noindex, follow` for it directly (Phase 32) — see that method's
     * own comment for why the noindex decision lives there instead of
     * here: `tube_seo_head()` is called explicitly and unconditionally
     * from `header.php`, always before this class's own `wp_head` could
     * ever fire, so a second tag added here would only ever trail
     * tube-seo's in the DOM as a duplicate, never able to correct it.
     *
     * @param string $template The template WordPress would otherwise use.
     */
    public function route_template(string $template): string
    {
        if ('1' !== Params::string(get_query_var(self::QUERY_VAR))) {
            return $template;
        }

        if (! is_user_logged_in()) {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        status_header(200);

        return TUBE_MEMBERS_DIR . '/includes/Render/templates/account-page.php';
    }
}
