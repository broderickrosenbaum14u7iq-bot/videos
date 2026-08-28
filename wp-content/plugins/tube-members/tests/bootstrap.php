<?php
/**
 * PHPUnit bootstrap for the Unit suite: Composer autoload plus the
 * handful of WordPress functions the pure-logic classes under test
 * actually call, stubbed to their real, documented behavior — never a
 * full WordPress load (see phpunit.xml.dist's own docblock for why).
 *
 * Every function below deliberately redeclares a real WordPress core
 * function name (never a `tube_members_`-prefixed one of this plugin's
 * own) so the classes under test can call the real name unmodified; this
 * file is loaded only by PHPUnit, never part of the plugin's own
 * autoload path, so it never collides with a real WordPress install.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (! function_exists('wp_strip_all_tags')) {
    /**
     * Mirrors WordPress core's real `wp_strip_all_tags()` exactly (see
     * `wp-includes/formatting.php`): unlike a bare `strip_tags()`, it
     * removes `<script>`/`<style>` tags AND their inner content, not just
     * the tags themselves.
     *
     * @param string $text The raw text to strip.
     */
    function wp_strip_all_tags(string $text): string // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- this IS WordPress core's own wp_strip_all_tags(), stubbed for the Unit suite (see this file's own docblock), never a new global this plugin declares for real.
    {
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text) ?? $text;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- this function IS the wp_strip_all_tags() stub itself; it cannot call itself.
        $text = strip_tags($text);

        return trim($text);
    }
}

if (! function_exists('sanitize_user')) {
    /**
     * A minimal stand-in for WordPress core's real `sanitize_user()`.
     *
     * @param string $username The raw candidate username.
     */
    function sanitize_user(string $username): string // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- this IS WordPress core's own sanitize_user(), stubbed for the Unit suite (see this file's own docblock).
    {
        $username = wp_strip_all_tags($username);
        $username = preg_replace('/[^a-z0-9 _.\-@]/i', '', $username) ?? '';

        return trim($username);
    }
}

if (! function_exists('sanitize_title')) {
    /**
     * A minimal stand-in for WordPress core's real `sanitize_title()`
     * (deliberately without its real accent-transliteration step — see
     * `UniqueLoginTest`'s own docblock for why).
     *
     * @param string $title The raw candidate title/slug source.
     */
    function sanitize_title(string $title): string // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- this IS WordPress core's own sanitize_title(), stubbed for the Unit suite (see this file's own docblock).
    {
        $title = strtolower($title);
        $title = preg_replace('/[^a-z0-9\-]+/', '-', $title) ?? '';

        return trim($title, '-');
    }
}

if (! function_exists('username_exists')) {
    /**
     * Stub controlled per-test via
     * `Tube_Members\Tests\Unit\Support\FakeUsernameRegistry::$taken`
     * (a plain global array of already-"taken" usernames) — the same
     * "smallest fake that makes the real function's contract
     * controllable" approach as tube-core's own in-memory fixtures.
     *
     * @param string $username The candidate username to check.
     */
    function username_exists(string $username): bool // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- this IS WordPress core's own username_exists(), stubbed for the Unit suite (see this file's own docblock).
    {
        return in_array($username, \Tube_Members\Tests\Unit\Support\FakeUsernameRegistry::$taken, true);
    }
}

if (! function_exists('wp_rand')) {
    /**
     * A minimal stand-in for WordPress core's real `wp_rand()`.
     *
     * @param int $min The minimum value, inclusive.
     * @param int $max The maximum value, inclusive.
     */
    function wp_rand(int $min, int $max): int // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- this IS WordPress core's own wp_rand(), stubbed for the Unit suite (see this file's own docblock).
    {
        return random_int($min, $max);
    }
}

if (! function_exists('get_transient')) {
    /**
     * A minimal in-memory stand-in for WordPress core's real
     * `get_transient()`/`set_transient()`/`delete_transient()` trio
     * (`wp-includes/option.php`), backed by
     * `Tube_Members\Tests\Unit\Support\FakeTransientStore` (a typed
     * static property, not a raw `$GLOBALS[]` array — the same
     * PHPStan-checkable "fake's control surface" convention
     * `FakeUsernameRegistry` already establishes for `username_exists()`
     * in this same test suite) rather than `wp_options` — mirrors the
     * real contract these three functions share (a value expires and
     * reads back as `false` once its TTL elapses) closely enough to
     * exercise `RedisRateLimiter::attempt_via_transient_fallback()`
     * without a real WordPress/database bootstrap.
     *
     * Deliberately round-trips every stored value through `(string)`
     * before handing it back, the same way a real `wp_options` row
     * does (its `option_value` column is TEXT — a plain scalar written
     * through `update_option()` does not come back as the same PHP
     * type it went in as). An earlier version of this stub preserved
     * the exact PHP type instead, which is why the first version of
     * `attempt_via_transient_fallback()`'s `is_int($stored)` check
     * passed every Unit test here while being silently broken against
     * a real WordPress install (confirmed live during the 2026-08-28
     * CRIT-2 fix: every attempt read back "not an int" and reset to 1,
     * so the fallback rate limit never actually engaged) — this stub
     * now catches that exact class of bug instead of hiding it.
     *
     * @param string $transient The transient's name.
     *
     * @return string|false The stored value as a string, or `false` if absent/expired.
     */
    function get_transient(string $transient) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- this IS WordPress core's own get_transient(), stubbed for the Unit suite (see this file's own docblock).
    {
        $entry = \Tube_Members\Tests\Unit\Support\FakeTransientStore::$entries[ $transient ] ?? null;

        if (null === $entry || $entry['expires_at'] < time()) {
            return false;
        }

        return $entry['value'];
    }

    /**
     * Stores a transient value.
     *
     * @param string $transient  The transient's name.
     * @param mixed  $value      The value to store.
     * @param int    $expiration Seconds until expiry.
     */
    function set_transient(string $transient, $value, int $expiration): bool // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- this IS WordPress core's own set_transient(), stubbed for the Unit suite (see this file's own docblock).
    {
        \Tube_Members\Tests\Unit\Support\FakeTransientStore::$entries[ $transient ] = [
            'value'      => is_scalar($value) ? (string) $value : '',
            'expires_at' => time() + $expiration,
        ];

        return true;
    }

    /**
     * Deletes a stored transient value.
     *
     * @param string $transient The transient's name.
     */
    function delete_transient(string $transient): bool // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- this IS WordPress core's own delete_transient(), stubbed for the Unit suite (see this file's own docblock).
    {
        unset(\Tube_Members\Tests\Unit\Support\FakeTransientStore::$entries[ $transient ]);

        return true;
    }
}
