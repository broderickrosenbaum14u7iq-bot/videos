<?php
/**
 * Requests and completes a WordPress-native password reset.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Auth;

use Tube_Members\Routing\PasswordResetRouting;
use Tube_Members\Support\RedisRateLimiter;
use WP_Error;
use WP_User;

/**
 * Requests and completes a password reset — built entirely on
 * WordPress core's own reset-key primitives
 * ({@see get_password_reset_key()}/{@see check_password_reset_key()}/
 * {@see reset_password()}, the exact same functions `wp-login.php`'s
 * own native "Lost your password?" flow uses), not a bespoke token
 * scheme: no new crypto, no new storage column, no new expiry logic to
 * get subtly wrong. This class only owns the parts core doesn't --
 * anti-enumeration response shaping, rate limiting, and this plugin's
 * own Vietnamese, branded email/landing-page presentation.
 */
final class PasswordResetService
{
    private const MIN_PASSWORD_LENGTH = 8;

    /**
     * Maximum reset requests allowed per identity/IP within {@see self::RATE_LIMIT_WINDOW_SECONDS}.
     */
    private const RATE_LIMIT_MAX_ATTEMPTS = 5;

    /**
     * The request rate-limit window, in seconds (1 hour) -- short enough
     * that a legitimate visitor who mistyped their email isn't locked
     * out for long, long enough to make mailbox-spamming/timing-based
     * enumeration impractical.
     */
    private const RATE_LIMIT_WINDOW_SECONDS = HOUR_IN_SECONDS;

    /**
     * Construct around the collaborators this service requests/completes a reset through.
     *
     * @param RedisRateLimiter         $rate_limiter Bounds repeated reset requests, the same pattern
     *                                                 every other rate-limited endpoint in this plugin uses.
     * @param PasswordResetEmailSender $mailer      Builds and sends the reset email.
     */
    public function __construct(
        private readonly RedisRateLimiter $rate_limiter,
        private readonly PasswordResetEmailSender $mailer
    ) {
    }

    /**
     * Handle a "Quên mật khẩu?" request. Deliberately returns nothing
     * and never throws for "no such account" -- the caller always shows
     * the same generic "if an account exists, an email was sent"
     * message regardless of outcome (Phase requirement: "avoid user
     * enumeration where practical"). Only a rate-limit breach is
     * reported back, so a legitimate visitor who is actually being
     * throttled still gets an honest, actionable message rather than a
     * silently-swallowed request.
     *
     * @param string $login_or_email What the visitor typed -- WordPress's own
     *                                 `get_user_by()` already accepts either.
     * @param string $client_ip      The requester's IP, for rate limiting.
     *
     * @throws ValidationException If the rate limit is exceeded.
     */
    public function request_reset(string $login_or_email, string $client_ip): void
    {
        $login_or_email = trim($login_or_email);

        $identity_key = 'password_reset:id:' . md5(strtolower($login_or_email));
        $ip_key       = "password_reset:ip:{$client_ip}";

        $within_identity_limit = $this->rate_limiter->attempt(
            $identity_key,
            self::RATE_LIMIT_MAX_ATTEMPTS,
            self::RATE_LIMIT_WINDOW_SECONDS
        );
        $within_ip_limit       = $this->rate_limiter->attempt(
            $ip_key,
            self::RATE_LIMIT_MAX_ATTEMPTS * 2,
            self::RATE_LIMIT_WINDOW_SECONDS
        );

        if (! $within_identity_limit || ! $within_ip_limit) {
            throw new ValidationException(['_form' => 'Bạn đã yêu cầu quá nhiều lần. Vui lòng thử lại sau ít phút.']);
        }

        if ('' === $login_or_email) {
            // No enumeration signal either way -- an empty submission
            // simply does nothing further, same as a real email that
            // doesn't match any account (see below).
            return;
        }

        $user = is_email($login_or_email)
            ? get_user_by('email', $login_or_email)
            : get_user_by('login', $login_or_email);

        if (! $user instanceof WP_User) {
            // Deliberately silent -- the caller shows the same success
            // message whether or not an account exists.
            return;
        }

        $key = get_password_reset_key($user);

        if ($key instanceof WP_Error) {
            // A core-level failure generating the key (e.g. a database
            // write error) -- nothing this caller can usefully surface
            // differently from "no account," so stay silent here too
            // rather than leaking which case occurred.
            return;
        }

        $reset_url = PasswordResetRouting::url($user->user_login, $key);

        $this->mailer->send($user, $reset_url);
    }

    /**
     * Complete a reset: validate the key, validate the new password,
     * set it, and return the now-updated account.
     * {@see check_password_reset_key()} does not consume the key on its
     * own -- {@see reset_password()} is what clears it, so a failed
     * password-validation attempt against an otherwise-valid key can
     * safely be retried with a corrected password.
     *
     * @param string $login             The `login` query param from the reset link.
     * @param string $key               The `key` query param from the reset link.
     * @param string $new_password      The visitor's chosen new password.
     * @param string $new_password_confirm Must match $new_password exactly.
     *
     * @throws ValidationException If the key is invalid/expired, or the new password fails validation.
     */
    public function complete_reset(
        string $login,
        string $key,
        string $new_password,
        string $new_password_confirm
    ): WP_User {
        $user = check_password_reset_key($key, $login);

        if ($user instanceof WP_Error) {
            $code = $user->get_error_code();

            $message = 'expired_key' === $code
                ? 'Liên kết đặt lại mật khẩu đã hết hạn. Vui lòng yêu cầu liên kết mới.'
                : 'Liên kết đặt lại mật khẩu không hợp lệ.';

            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message is chosen from this method's own two hardcoded Vietnamese literals above, never user input; ResetPasswordController escapes it (via WP_REST_Response's own JSON encoding) at the point it actually reaches a response -- same posture RegistrationService's own identical-shape throw already documents.
            throw new ValidationException(['_form' => $message]);
        }

        $errors = $this->validate_new_password($new_password, $new_password_confirm);

        if ([] !== $errors) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $errors is validation feedback data carried on a thrown exception, never echoed here; every value in it is this method's own hardcoded Vietnamese literal, never user input -- same posture RegistrationService's own identical-shape throw already documents.
            throw new ValidationException($errors);
        }

        reset_password($user, $new_password);

        return $user;
    }

    /**
     * Validate the new password, per-field -- the exact same policy
     * {@see RegistrationService}'s own `validate()` already enforces for
     * a brand-new account's password, so a visitor can't be held to a
     * weaker or stronger standard depending on which flow they took.
     *
     * @param string $new_password         The visitor's chosen new password.
     * @param string $new_password_confirm Must match $new_password exactly.
     *
     * @return array<string, string> Field name => Vietnamese error message; empty when valid.
     */
    private function validate_new_password(string $new_password, string $new_password_confirm): array
    {
        $errors = [];

        if ('' === $new_password) {
            $errors['new_password'] = 'Vui lòng nhập mật khẩu mới.';
        } elseif (mb_strlen($new_password) < self::MIN_PASSWORD_LENGTH) {
            $errors['new_password'] = 'Mật khẩu phải có ít nhất 8 ký tự.';
        } elseif (1 !== preg_match('/[a-zA-Z]/', $new_password) || 1 !== preg_match('/[0-9]/', $new_password)) {
            $errors['new_password'] = 'Mật khẩu phải có cả chữ và số.';
        }

        if (! isset($errors['new_password']) && $new_password !== $new_password_confirm) {
            $errors['new_password_confirm'] = 'Mật khẩu xác nhận không khớp.';
        }

        return $errors;
    }
}
