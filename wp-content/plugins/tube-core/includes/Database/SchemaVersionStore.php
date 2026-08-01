<?php
/**
 * Wp_tube_schema_versions-backed migration tracking.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Database;

use Tube_Core\Migration\SchemaVersionRepositoryInterface;

/**
 * Backs SchemaVersionRepositoryInterface with wp_tube_schema_versions.
 *
 * This is the one table created directly via dbDelta() rather than
 * through the migration runner itself — it is the runner's own
 * bookkeeping table, so it must exist before any migration can be
 * tracked, per ARCHITECTURE.md §2.7 and §3.
 */
final class SchemaVersionStore implements SchemaVersionRepositoryInterface
{
    /**
     * Create wp_tube_schema_versions if it does not already exist.
     *
     * Safe to call on every plugin activation — dbDelta() only creates
     * what is missing and never drops or truncates existing data.
     */
    public static function install(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plugin_slug VARCHAR(50) NOT NULL,
            version VARCHAR(20) NOT NULL,
            applied_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY plugin_version (plugin_slug, version)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * The wp_tube_schema_versions table name, including the site's table prefix.
     */
    public static function table_name(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        return $wpdb->prefix . 'tube_schema_versions';
    }

    /**
     * {@inheritDoc}
     *
     * @param string $plugin_slug The plugin identity to look up.
     */
    public function applied_versions(string $plugin_slug): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT version, applied_at FROM %i WHERE plugin_slug = %s ORDER BY version ASC',
                self::table_name(),
                $plugin_slug
            ),
            ARRAY_A
        );

        $result = [];

        // wordpress-stubs types wpdb::get_results() as `array|object|null`
        // regardless of the $output_type argument (it can't discriminate on
        // a runtime constant) — the real shape below is exact given the
        // fixed two-column SELECT and ARRAY_A above.
        /** @var array<int, array{version: string, applied_at: string}> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $rows = (array) $rows;

        foreach ($rows as $row) {
            $result[ $row['version'] ] = $row['applied_at'];
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $plugin_slug The plugin identity to record against.
     * @param string $version     The migration version being recorded as applied.
     */
    public function record(string $plugin_slug, string $version): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->insert(
            self::table_name(),
            [
                'plugin_slug' => $plugin_slug,
                'version'     => $version,
                'applied_at'  => current_time('mysql', true),
            ],
            ['%s', '%s', '%s']
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param string $plugin_slug The plugin identity to forget the version for.
     * @param string $version     The migration version to un-record.
     */
    public function forget(string $plugin_slug, string $version): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->delete(
            self::table_name(),
            [
                'plugin_slug' => $plugin_slug,
                'version'     => $version,
            ],
            ['%s', '%s']
        );
    }
}
