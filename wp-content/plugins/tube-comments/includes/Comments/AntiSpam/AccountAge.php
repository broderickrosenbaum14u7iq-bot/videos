<?php
/**
 * Whether a member's account is younger than a given age.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Comments\AntiSpam;

/**
 * Whether a member's account is younger than a given age, used by
 * `SpamGuard` to apply `SpamPolicy::NEW_ACCOUNT_*`'s stricter limits.
 *
 * `WP_User::$user_registered` is stored in the site's configured local
 * time (WordPress core's own convention, the same one
 * `Tube_Comments\Http\CommentPresenter::present_one()` accounts for when
 * converting `created_at` the other direction) — `get_gmt_from_date()`
 * is WordPress core's own local-to-GMT conversion for exactly this
 * column, so this never drifts by the site's UTC offset.
 */
final class AccountAge
{
    /**
     * Whether $user_id's account is younger than $threshold_seconds.
     *
     * @param int $user_id           The account to check.
     * @param int $threshold_seconds The age threshold, in seconds.
     */
    public static function is_new(int $user_id, int $threshold_seconds): bool
    {
        $user = get_userdata($user_id);

        if (false === $user) {
            // An unresolvable user is never held to the stricter new-account
            // limits — the normal limits already apply as the safe default,
            // and this should not be reachable for a real authenticated request.
            return false;
        }

        $registered_gmt       = get_gmt_from_date($user->user_registered);
        $registered_timestamp = strtotime($registered_gmt . ' UTC');

        if (false === $registered_timestamp) {
            return false;
        }

        $age_seconds = time() - $registered_timestamp;

        return $age_seconds < $threshold_seconds;
    }

    /**
     * No instances — a single static helper.
     */
    private function __construct()
    {
    }
}
