<?php
/**
 * Keeps normal frontend members out of wp-admin.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Capability;

use Tube_Members\Routing\AccountRouting;

/**
 * Keeps normal frontend members (the `subscriber` role, no publishing/
 * admin capabilities) out of wp-admin, per Phase 8. `edit_posts` is the
 * dividing line: every role above Subscriber (Contributor and up,
 * including Administrator/Editor) has it, Subscriber does not — so this
 * needs no hardcoded role name and keeps working if a site later adds a
 * custom "member" role with the same no-publishing-capability shape.
 *
 * Hooked on `admin_init`, which WordPress fires for real wp-admin page
 * loads. Explicitly skipped for `wp_doing_ajax()` (admin-ajax.php also
 * fires `admin_init`) and `wp_doing_cron()`, per Phase 8's "Exceptions
 * must remain functional: admin-ajax.php... Do NOT break cron" — REST
 * requests never fire `admin_init` at all, so they need no explicit
 * exception here.
 */
final class MemberRoleGuard
{
    /**
     * `admin_init` callback: redirect a logged-in non-privileged member
     * away from wp-admin to their frontend account page.
     */
    public function block_backend_access(): void
    {
        if (wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        if (! is_user_logged_in()) {
            // Not our concern: WordPress's own auth_redirect() already
            // sends a guest to wp-login.php for any wp-admin request.
            return;
        }

        if (current_user_can('edit_posts')) {
            return;
        }

        wp_safe_redirect(AccountRouting::url());
        exit;
    }

    /**
     * `show_admin_bar` filter callback: hide the admin bar on the
     * frontend for the same non-privileged members blocked above.
     *
     * @param bool $show The admin bar's current visibility.
     */
    public function hide_admin_bar_for_members(bool $show): bool
    {
        if (! is_user_logged_in()) {
            return $show;
        }

        return current_user_can('edit_posts') ? $show : false;
    }
}
