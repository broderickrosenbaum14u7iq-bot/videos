<?php
/**
 * Integration tests for SitemapRouting's non-terminating behavior.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Tests\Integration\Sitemap;

use PHPUnit\Framework\TestCase;
use Tube_Seo\Sitemap\SitemapRouting;
use WP_Query;

/**
 * Exercises `SitemapRouting`'s query-var registration and its "not a
 * match" / "file doesn't exist" branches — the ones that return normally.
 * The success branch (`readfile()` + `header()` + `exit`) can't be run
 * inside PHPUnit without terminating the test process, and is instead
 * verified live via a real HTTP request against the running site (see
 * PHASE-9.md's live verification section).
 */
final class SitemapRoutingIntegrationTest extends TestCase
{
    /**
     * Restore global query state after each test.
     */
    protected function tearDown(): void
    {
        set_query_var('tube_seo_sitemap_file', '');

        global $wp_query;
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the same global this test deliberately manipulates, mirroring SeoHeadIntegrationTest's own teardown.
        $wp_query = new WP_Query();
    }

    /**
     * Register_query_var() adds this route's query var to WordPress's public query var list.
     */
    public function test_register_query_var_adds_the_sitemap_file_var(): void
    {
        $routing = new SitemapRouting();

        $vars = $routing->register_query_var(['existing_var']);

        self::assertContains('tube_seo_sitemap_file', $vars);
        self::assertContains('existing_var', $vars);
    }

    /**
     * When the query var is empty (not this route's request), maybe_serve() does nothing.
     */
    public function test_maybe_serve_does_nothing_when_the_query_var_is_empty(): void
    {
        global $wp_query;
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberately simulating query state, the same technique SeoHeadIntegrationTest already uses.
        $wp_query = new WP_Query();

        set_query_var('tube_seo_sitemap_file', '');

        (new SitemapRouting())->maybe_serve();

        self::assertFalse($wp_query->is_404());
    }

    /**
     * A filename outside the allowed pattern (e.g. a path-traversal attempt) is ignored, not served or 404'd.
     */
    public function test_maybe_serve_ignores_a_filename_outside_the_allowed_pattern(): void
    {
        global $wp_query;
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberately simulating query state, the same technique SeoHeadIntegrationTest already uses.
        $wp_query = new WP_Query();

        set_query_var('tube_seo_sitemap_file', '../../../wp-config.php');

        (new SitemapRouting())->maybe_serve();

        self::assertFalse($wp_query->is_404());
    }

    /**
     * A validly-named sitemap file that doesn't exist on disk yet (not generated) results in a real 404.
     */
    public function test_maybe_serve_sets_404_for_a_valid_filename_that_does_not_exist(): void
    {
        global $wp_query;
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberately simulating query state, the same technique SeoHeadIntegrationTest already uses.
        $wp_query = new WP_Query();

        set_query_var('tube_seo_sitemap_file', 'video-sitemap-999.xml');

        (new SitemapRouting())->maybe_serve();

        self::assertTrue($wp_query->is_404());
    }
}
