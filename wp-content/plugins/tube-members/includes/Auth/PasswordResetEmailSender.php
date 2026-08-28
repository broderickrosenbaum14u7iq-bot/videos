<?php
/**
 * Builds and sends the password-reset email.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Auth;

use WP_User;

/**
 * Builds and sends the password-reset email — the same "content
 * construction separate from when-to-send" split
 * {@see \Tube_Members\Email\VerificationEmailSender} already
 * establishes, for the same reason (transport can change later without
 * touching PasswordResetService's own logic). `wp_mail()` only — no
 * paid provider, no SMTP plugin, no hardcoded credentials, same posture
 * as every other outbound email this plugin sends.
 *
 * Plain text only, same posture as the verification email — this is a
 * security-sensitive transactional email, not marketing.
 */
final class PasswordResetEmailSender
{
    /**
     * Send one password-reset email. Never throws — a `wp_mail()`
     * failure (e.g. no local MTA configured, this project's own Docker
     * dev environment) is reported back as `false` for the caller to
     * treat as non-fatal; the reset link itself was already generated
     * and remains valid regardless of whether this particular email
     * delivery succeeded.
     *
     * @param WP_User $user      The account a reset was requested for.
     * @param string  $reset_url The one-time link this user must click.
     */
    public function send(WP_User $user, string $reset_url): bool
    {
        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment, WordPress.WP.I18n.NonSingularStringLiteralText -- outbound email content built from admin-configured site config (site name), not translator-facing markup -- same posture VerificationEmailSender's own identical comment documents.
        $subject = sprintf('Đặt lại mật khẩu của bạn - %s', $site_name);

        $body = sprintf(
            "Xin chào %s,\n\n" .
            "Chúng tôi nhận được yêu cầu đặt lại mật khẩu cho tài khoản của bạn tại %s.\n\n" .
            "Nhấn vào liên kết dưới đây để đặt mật khẩu mới:\n\n" .
            "%s\n\n" .
            "Liên kết có hiệu lực trong 24 giờ và chỉ dùng được một lần.\n\n" .
            "Nếu bạn không yêu cầu đặt lại mật khẩu, bạn có thể bỏ qua email này -- mật khẩu hiện tại của bạn sẽ không thay đổi.\n",
            $user->display_name,
            $site_name,
            $reset_url
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- dev-only visibility into a reset link wp_mail() cannot actually deliver without a locally-configured MTA (same posture VerificationEmailSender's own identical comment documents) -- never runs when WP_DEBUG is off (production), never rendered on any page, only written to the PHP error log.
            error_log('[tube-members] password reset link for user #' . $user->ID . ': ' . $reset_url);
        }

        return wp_mail($user->user_email, $subject, $body);
    }
}
