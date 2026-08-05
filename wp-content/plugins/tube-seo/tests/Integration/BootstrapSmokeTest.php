<?php
/**
 * Smoke test: confirms the integration bootstrap loaded real WordPress
 * with tube-core, tube-player, tube-search, and tube-seo all active.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * If this fails, no other integration test in this suite can be
 * trusted either — fix tests/Integration/bootstrap.php first.
 */
final class BootstrapSmokeTest extends TestCase
{
    /**
     * WordPress core and all four plugins are loaded.
     */
    public function test_wordpress_and_all_plugins_are_loaded(): void
    {
        self::assertTrue(class_exists(\Tube_Core\Plugin::class));
        self::assertTrue(class_exists(\Tube_Player\Plugin::class));
        self::assertTrue(class_exists(\Tube_Search\Plugin::class));
        self::assertTrue(class_exists(\Tube_Seo\Plugin::class));
        self::assertTrue(function_exists('tube_seo_head'));
    }
}
