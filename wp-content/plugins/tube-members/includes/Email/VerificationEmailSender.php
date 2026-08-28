<?php
/**
 * Builds and sends the verification email.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Email;

use WP_User;

/**
 * Builds and sends the verification email — the one place mail content
 * construction happens, kept separate from {@see EmailVerificationService}
 * (which only decides WHEN to send) so the transport can change later
 * (real SMTP configured at the server/WordPress level, a future queued
 * sender) without touching registration/resend logic at all. Uses
 * `wp_mail()` only — no paid provider, no SMTP plugin, no hardcoded
 * credentials (2026-08-27 email-verification task, Phase 5).
 *
 * Plain text only (Phase 24: "plain text is acceptable and preferred for
 * V1") — no HTML template system, no tracking pixel; this is a
 * verification email, not marketing.
 */
final class VerificationEmailSender
{
    /**
     * Send one verification email. Never throws — a `wp_mail()` failure
     * (e.g. no local MTA configured, exactly this project's own Docker
     * dev environment — see this class's own docblock reference in
     * EmailVerificationService) is reported back as `false`, for the
     * caller to treat as non-fatal (Phase 4: registration must never
     * roll back because mail delivery failed).
     *
     * @param WP_User $user             The account to verify.
     * @param string  $verification_url The one-time link this user must click.
     */
    public function send(WP_User $user, string $verification_url): bool
    {
        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment, WordPress.WP.I18n.NonSingularStringLiteralText -- these are outbound email content built from admin-configured site config (site name), not translator-facing markup; there is no i18n string-review tooling in this project for wp_mail() bodies, only for esc_html_e()/__() calls that reach a rendered page (see every other tube-* plugin's own Vietnamese-literal strings for the same posture).
        $subject = sprintf('Xác thực email của bạn - %s', $site_name);

        $body = sprintf(
            "Xin chào %s,\n\n" .
            "Bạn vừa đăng ký tài khoản tại %s.\n\n" .
            "Nhấn vào liên kết dưới đây để xác thực email:\n\n" .
            "%s\n\n" .
            "Liên kết có hiệu lực trong 24 giờ.\n\n" .
            "Nếu bạn không đăng ký tài khoản này, bạn có thể bỏ qua email.\n",
            $user->display_name,
            $site_name,
            $verification_url
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- dev-only visibility into a verification link wp_mail() cannot actually deliver without a locally-configured MTA (confirmed live, 2026-08-27: wp_mail() returns false in this Docker environment) -- never runs when WP_DEBUG is off (production), never rendered on any page, only written to the PHP error log.
            error_log('[tube-members] verification link for user #' . $user->ID . ': ' . $verification_url);
        }

        return wp_mail($user->user_email, $subject, $body);
    }
}
