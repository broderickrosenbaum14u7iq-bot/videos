<?php
/**
 * Validates and creates a new frontend member account.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Auth;

use Tube_Members\Support\RedisRateLimiter;
use Tube_Members\Support\UniqueLogin;
use WP_Error;
use WP_User;

/**
 * Validates and creates a new frontend member account, per Phase 5.
 *
 * Uses WordPress's own `wp_insert_user()` (core password hashing, core
 * uniqueness constraints) — no second password database (Phase 3).
 * Every new account is created with the `subscriber` role and nothing
 * else; `Tube_Members\Capability\MemberRoleGuard` is what keeps a
 * subscriber out of wp-admin, not anything done here.
 *
 * A safe internal `user_login` is generated from the email's local part
 * (never asked of the visitor — Phase 5's "internal username may be
 * generated safely from email/display name") and de-duplicated with a
 * short random numeric suffix on collision.
 */
final class RegistrationService
{
    /**
     * Minimum acceptable password length — long enough to resist casual
     * guessing, short enough not to make registration painful (Phase 5).
     */
    private const MIN_PASSWORD_LENGTH = 8;

    /**
     * Maximum registration attempts allowed per IP within {@see self::RATE_LIMIT_WINDOW_SECONDS}.
     */
    private const RATE_LIMIT_MAX_ATTEMPTS = 8;

    /**
     * The rate-limit window, in seconds.
     */
    private const RATE_LIMIT_WINDOW_SECONDS = 1800;

    /**
     * Construct around the rate limiter used to bound accounts created per IP.
     *
     * @param RedisRateLimiter $rate_limiter Bounds how many accounts one IP may create.
     */
    public function __construct(private readonly RedisRateLimiter $rate_limiter)
    {
    }

    /**
     * Validate the given registration input and, if valid, create the
     * account. Never partially creates a user — validation runs
     * completely before `wp_insert_user()` is ever called.
     *
     * @param string $display_name     The visitor's chosen display name.
     * @param string $email            The visitor's email address.
     * @param string $password         The visitor's chosen password.
     * @param string $password_confirm Must match $password exactly.
     * @param string $client_ip        The requester's IP, for rate limiting.
     *
     * @throws ValidationException If any field fails validation, or the rate limit is exceeded.
     */
    public function register(
        string $display_name,
        string $email,
        string $password,
        string $password_confirm,
        string $client_ip
    ): WP_User {
        $within_limit = $this->rate_limiter->attempt(
            "register:ip:{$client_ip}",
            self::RATE_LIMIT_MAX_ATTEMPTS,
            self::RATE_LIMIT_WINDOW_SECONDS
        );

        if (! $within_limit) {
            throw new ValidationException(['_form' => 'Bạn thao tác quá nhanh. Vui lòng thử lại sau ít phút.']);
        }

        $errors = $this->validate($display_name, $email, $password, $password_confirm);

        if ([] !== $errors) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $errors is validation feedback data carried on a thrown exception, never echoed here; every value in it is this method's own hardcoded Vietnamese literal, never user input, and RegistrationController escapes it (via wp_send_json/WP_REST_Response's own JSON encoding) at the point it actually reaches a response.
            throw new ValidationException($errors);
        }

        $display_name = trim(wp_strip_all_tags($display_name));
        $email        = sanitize_email($email);
        $user_login   = UniqueLogin::generate($email, $display_name);

        $user_id = wp_insert_user(
            [
                'user_login'   => $user_login,
                'user_email'   => $email,
                'user_pass'    => $password,
                'display_name' => $display_name,
                'nickname'     => $display_name,
                'role'         => 'subscriber',
            ]
        );

        if ($user_id instanceof WP_Error) {
            throw new ValidationException(['_form' => 'Không thể tạo tài khoản. Vui lòng thử lại.']);
        }

        $new_user_id = (int) $user_id;
        $user        = get_userdata($new_user_id);

        if (! $user instanceof WP_User) {
            throw new ValidationException(['_form' => 'Không thể tạo tài khoản. Vui lòng thử lại.']);
        }

        return $user;
    }

    /**
     * Validate registration input, per-field.
     *
     * @param string $display_name     The visitor's chosen display name.
     * @param string $email            The visitor's email address.
     * @param string $password         The visitor's chosen password.
     * @param string $password_confirm Must match $password exactly.
     *
     * @return array<string, string> Field name => Vietnamese error message; empty when valid.
     */
    private function validate(string $display_name, string $email, string $password, string $password_confirm): array
    {
        $errors = [];

        $display_name = trim(wp_strip_all_tags($display_name));

        if ('' === $display_name) {
            $errors['display_name'] = 'Vui lòng nhập tên hiển thị.';
        } elseif (mb_strlen($display_name) < 2 || mb_strlen($display_name) > 50) {
            $errors['display_name'] = 'Tên hiển thị phải có từ 2 đến 50 ký tự.';
        }

        if ('' === trim($email)) {
            $errors['email'] = 'Vui lòng nhập email.';
        } elseif (! is_email($email)) {
            $errors['email'] = 'Email không hợp lệ.';
        } elseif (email_exists($email)) {
            $errors['email'] = 'Email này đã được sử dụng.';
        }

        if ('' === $password) {
            $errors['password'] = 'Vui lòng nhập mật khẩu.';
        } elseif (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors['password'] = 'Mật khẩu phải có ít nhất 8 ký tự.';
        } elseif (1 !== preg_match('/[a-zA-Z]/', $password) || 1 !== preg_match('/[0-9]/', $password)) {
            $errors['password'] = 'Mật khẩu phải có cả chữ và số.';
        }

        if (! isset($errors['password']) && $password !== $password_confirm) {
            $errors['password_confirm'] = 'Mật khẩu xác nhận không khớp.';
        }

        return $errors;
    }
}
