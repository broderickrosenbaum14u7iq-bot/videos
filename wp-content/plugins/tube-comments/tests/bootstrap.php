<?php
/**
 * PHPUnit bootstrap for the Unit suite: Composer autoload plus the
 * handful of WordPress functions the pure-logic classes under test
 * actually call, stubbed to their real, documented behavior — never a
 * full WordPress load (see phpunit.xml.dist's own docblock for why).
 *
 * The function below deliberately redeclares a real WordPress core
 * function name (never a `tube_comments_`-prefixed one of this plugin's
 * own) so the classes under test can call the real name unmodified; this
 * file is loaded only by PHPUnit, never part of the plugin's own
 * autoload path, so it never collides with a real WordPress install.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (! function_exists('wp_strip_all_tags')) {
    /**
     * Mirrors WordPress core's real `wp_strip_all_tags()` exactly (see
     * `wp-includes/formatting.php`): unlike a bare `strip_tags()`, it
     * removes `<script>`/`<style>` tags AND their inner content, not just
     * the tags themselves — the behavior this plugin's live XSS
     * verification (`<script>alert(1)</script>...`) actually depends on.
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
