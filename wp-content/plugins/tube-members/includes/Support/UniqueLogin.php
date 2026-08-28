<?php
/**
 * Generates a safe, unique wp_users.user_login from an email address.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Support;

/**
 * Generates a safe, unique `wp_users.user_login` from an email address
 * (falling back to a slugified display name) — shared between
 * `Tube_Members\Auth\RegistrationService` (manual registration) and
 * `Tube_Members\OAuth\GoogleOAuthController` (a brand-new Google
 * sign-in), since both need the exact same "safe internal username,
 * never asked of the visitor" behavior (Phase 5/Phase 6).
 */
final class UniqueLogin
{
    /**
     * Generate a safe, unique `user_login` from an email address.
     *
     * @param string $email               The account's email address.
     * @param string $fallback_display_name Used only if the email's local part sanitizes away to nothing.
     */
    public static function generate(string $email, string $fallback_display_name = ''): string
    {
        $local_part = strtolower(explode('@', $email)[0]);
        $base       = sanitize_user($local_part, true);

        if ('' === $base && '' !== $fallback_display_name) {
            $base = sanitize_user(sanitize_title($fallback_display_name), true);
        }

        if ('' === $base) {
            $base = 'member';
        }

        $candidate = $base;

        while (username_exists($candidate)) {
            $candidate = $base . wp_rand(100, 99999);
        }

        return $candidate;
    }
}
