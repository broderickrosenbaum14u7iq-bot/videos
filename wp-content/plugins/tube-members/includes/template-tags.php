<?php
/**
 * Template-tag wrappers other plugins/the theme call directly.
 *
 * No `ABSPATH` guard here — `tube-members.php` already exits before
 * `require_once`-ing this file (the same convention tube-core/tube-player/
 * tube-search's own `includes/template-tags.php` already document).
 *
 * @package Tube_Members
 */

declare(strict_types=1);

use Tube_Members\Email\EmailVerificationService;
use Tube_Members\Email\VerificationEmailSender;
use Tube_Members\Profile\AvatarService;
use Tube_Members\Routing\AccountRouting;

/**
 * Whether the current visitor is an authenticated frontend member —
 * the single check `tube-comments` (and any future account-gated
 * feature) should use rather than calling `is_user_logged_in()`
 * directly, so the "what counts as a member" decision has one owner.
 */
function tube_members_is_logged_in(): bool
{
    return is_user_logged_in();
}

/**
 * This user's avatar URL, per {@see \Tube_Members\Profile\AvatarService}'s
 * priority order. Defaults to the current visitor when $user_id is omitted.
 *
 * @param int|null $user_id Defaults to the current visitor.
 */
function tube_members_get_avatar_url(?int $user_id = null): string
{
    $user_id = $user_id ?? get_current_user_id();

    if ($user_id <= 0) {
        return (new AvatarService())->default_avatar_url(0);
    }

    return (new AvatarService())->url_for($user_id);
}

/**
 * This user's display name. Defaults to the current visitor.
 *
 * @param int|null $user_id Defaults to the current visitor.
 */
function tube_members_get_display_name(?int $user_id = null): string
{
    $user_id = $user_id ?? get_current_user_id();

    if ($user_id <= 0) {
        return '';
    }

    $user = get_userdata($user_id);

    return false === $user ? '' : $user->display_name;
}

/**
 * The frontend account page's canonical URL.
 */
function tube_members_account_url(): string
{
    return AccountRouting::url();
}

/**
 * Whether a member may comment/reply/report right now — the single
 * check any account-gated action (`tube-comments`'s root-comment/
 * reply/report controllers) should use rather than reading
 * `tube_email_verified` user meta directly, so "what counts as
 * verified" (the capability bypass, the pre-feature grandfather
 * clause) has exactly one owner (2026-08-27 email-verification task).
 *
 * Defaults to the current visitor when $user_id is omitted; returns
 * `false` for a guest (no account to be verified) rather than throwing.
 *
 * @param int|null $user_id Defaults to the current visitor.
 */
function tube_members_is_email_verified(?int $user_id = null): bool
{
    $user_id = $user_id ?? get_current_user_id();

    if ($user_id <= 0) {
        return false;
    }

    $user = get_userdata($user_id);

    if (false === $user) {
        return false;
    }

    $service = new EmailVerificationService(new VerificationEmailSender());

    return $service->is_verified($user);
}
