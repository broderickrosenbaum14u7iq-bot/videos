<?php
/**
 * Smoke test: confirms the integration bootstrap loaded real WordPress
 * with tube-core, tube-cache, and tube-search all active.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * If this fails, no other integration test in this suite can be
 * trusted either — fix tests/Integration/bootstrap.php first.
 */
final class BootstrapSmokeTest extends TestCase
{
    /**
     * WordPress core, tube-core, tube-cache, and tube-search are all loaded.
     */
    public function test_wordpress_and_all_three_plugins_are_loaded(): void
    {
        self::assertTrue(class_exists(\Tube_Core\Plugin::class));
        self::assertTrue(class_exists(\Tube_Cache\Plugin::class));
        self::assertTrue(class_exists(\Tube_Search\Plugin::class));
        self::assertTrue(function_exists('tube_search_related_videos'));
        self::assertTrue(function_exists('tube_search_trending'));
        self::assertTrue(function_exists('tube_search_most_viewed'));
        self::assertTrue(function_exists('tube_search_recently_added'));
        self::assertTrue(function_exists('tube_search_query'));
    }

    /**
     * The wp_tube_search_index table exists (created by Migration001, run against the real database).
     */
    public function test_search_index_table_exists(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $table = $wpdb->prefix . 'tube_search_index';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off schema existence check in a smoke test, not a runtime query path.
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        self::assertSame($table, $found);
    }
}
