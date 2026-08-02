<?php
/**
 * Smoke test: confirms the integration bootstrap actually loaded a real
 * WordPress install with a live database connection, before any other
 * integration test relies on that.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * If this fails, no other integration test can be trusted either — fix
 * tests/Integration/bootstrap.php first.
 */
final class BootstrapSmokeTest extends TestCase
{
    /**
     * WordPress core is loaded and $wpdb has a live connection.
     */
    public function test_wordpress_is_loaded_with_a_live_database_connection(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        self::assertInstanceOf(\wpdb::class, $wpdb);

        $result = $wpdb->get_var('SELECT 1');
        self::assertSame('1', $result);
    }

    /**
     * The plugin's own autoloader is wired up.
     */
    public function test_plugin_autoloader_is_loaded(): void
    {
        self::assertTrue(
            interface_exists(\Tube_Core\Import\VideoImporterInterface::class)
        );
    }
}
