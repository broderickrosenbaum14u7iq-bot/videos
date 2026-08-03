<?php
/**
 * Smoke test: confirms the integration bootstrap loaded real WordPress
 * with both tube-core and tube-player active, before any other
 * integration test relies on that.
 *
 * @package Tube_Player
 */

declare(strict_types=1);

namespace Tube_Player\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * If this fails, no other integration test in this suite can be
 * trusted either — fix tests/Integration/bootstrap.php first.
 */
final class BootstrapSmokeTest extends TestCase
{
    /**
     * WordPress core, tube-core, and tube-player are all loaded.
     */
    public function test_wordpress_and_both_plugins_are_loaded(): void
    {
        self::assertTrue(class_exists(\Tube_Core\Plugin::class));
        self::assertTrue(class_exists(\Tube_Player\Plugin::class));
        self::assertTrue(function_exists('tube_player_get_image_html'));
        self::assertTrue(function_exists('tube_player_get_embed_html'));
    }
}
