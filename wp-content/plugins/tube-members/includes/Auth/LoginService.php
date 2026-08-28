<?php
/**
 * Validates email/username + password credentials.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Auth;

use Tube_Members\Support\RedisRateLimiter;
use WP_Error;
use WP_User;

/**
 * Validates email/username + password credentials via WordPress's own
 * `wp_authenticate()` (core supports logging in by either email or
 * `user_login` out of the box, satisfying Phase 4's "Email hoặc tên
 * đăng nhập" field with no custom lookup needed) — no second password
 * database (Phase 3).
 *
 * Deliberately returns one generic Vietnamese error regardless of
 * whether the identifier or the password was wrong, so this endpoint
 * cannot be used to enumerate which emails have accounts.
 */
final class LoginService
{
    /**
     * Maximum attempts allowed per rate-limit key within {@see self::RATE_LIMIT_WINDOW_SECONDS}.
     */
    private const RATE_LIMIT_MAX_ATTEMPTS = 8;

    /**
     * The rate-limit window, in seconds.
     */
    private const RATE_LIMIT_WINDOW_SECONDS = 900;

    /**
     * Construct around the rate limiter used to bound repeated failed attempts.
     *
     * @param RedisRateLimiter $rate_limiter Bounds repeated failed attempts, per Phase 21.
     */
    public function __construct(private readonly RedisRateLimiter $rate_limiter)
    {
    }

    /**
     * Validate credentials, rate-limited by identity and by IP.
     *
     * @param string $login     Email or username, as typed by the visitor.
     * @param string $password  The visitor's password, in plain text (never logged).
     * @param string $client_ip The requester's IP, for rate limiting.
     *
     * @throws ValidationException If the rate limit is exceeded or the credentials are wrong.
     */
    public function authenticate(string $login, string $password, string $client_ip): WP_User
    {
        $login = trim($login);

        $identity_key = 'login:id:' . md5(strtolower($login));
        $ip_key       = "login:ip:{$client_ip}";

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
            throw new ValidationException(['_form' => 'Bạn đã thử quá nhiều lần. Vui lòng thử lại sau ít phút.']);
        }

        if ('' === $login || '' === $password) {
            throw new ValidationException(['_form' => 'Vui lòng nhập đầy đủ thông tin đăng nhập.']);
        }

        $user = wp_authenticate($login, $password);

        if ($user instanceof WP_Error) {
            throw new ValidationException(['_form' => 'Email/tên đăng nhập hoặc mật khẩu không đúng.']);
        }

        $this->rate_limiter->reset($identity_key);

        return $user;
    }
}
