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
