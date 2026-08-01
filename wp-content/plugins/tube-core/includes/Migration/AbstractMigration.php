<?php
/**
 * Convenience base class for concrete migrations.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Migration;

use wpdb;

/**
 * Convenience base class for concrete migrations.
 *
 * Provides `$wpdb` access and the two schema-mutation primitives every
 * migration needs: applying CREATE/ALTER statements through dbDelta(),
 * and dropping a table outright (which dbDelta() has no equivalent for,
 * needed by down() methods that reverse a CREATE).
 *
 * None of the tables owned by tube-* plugins have a WP_Query/WP_Meta_Query
 * equivalent, so direct $wpdb access here is the documented, intentional
 * exception described in ARCHITECTURE.md §2.5 and §11 — not a shortcut.
 */
abstract class AbstractMigration implements MigrationInterface
{
    /**
     * The global WordPress database access object.
     */
    protected function db(): wpdb
    {
        global $wpdb;
        /** @var wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        return $wpdb;
    }

    /**
     * The charset/collate clause to append to CREATE TABLE statements,
     * matching the site's configured database charset and collation.
     */
    protected function charset_collate(): string
    {
        return $this->db()->get_charset_collate();
    }

    /**
     * Run one or more semicolon-terminated CREATE/ALTER TABLE statements
     * through WordPress's dbDelta(), which creates missing tables/columns
     * and safely diffs existing ones — never drops or truncates data.
     *
     * @param string $sql One or more semicolon-terminated CREATE/ALTER TABLE statements.
     *
     * @return array<int, string> dbDelta()'s own report of the changes it made.
     */
    protected function apply_schema(string $sql): array
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        return array_values(dbDelta($sql));
    }

    /**
     * Drop a table outright.
     *
     * Used from down() methods only, to reverse a table created in up().
     * dbDelta() cannot drop tables, so this is a direct query by
     * necessity. $table_name is always derived from `$wpdb->prefix` plus
     * a literal suffix (never user input), so it is not parameterizable
     * via `wpdb::prepare()` — identifiers cannot be bound placeholders.
     *
     * @param string $table_name Full table name, including the site's table prefix.
     */
    protected function drop_table(string $table_name): void
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- migration rollback; no dbDelta/WP_Query equivalent exists for dropping a table. See ARCHITECTURE.md §2.5, §3, §11.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifier only, derived from $wpdb->prefix, never user input; identifiers cannot be bound via wpdb::prepare().
        $this->db()->query("DROP TABLE IF EXISTS {$table_name}");
    }

    /**
     * Drop a single index from a table.
     *
     * Used from down() methods that reverse an index-only change. Like
     * drop_table(), dbDelta() has no equivalent — it only ever adds
     * columns/indexes when diffing a CREATE TABLE statement, never
     * removes them, so removing one is a direct query by necessity.
     *
     * @param string $table_name Full table name, including the site's table prefix.
     * @param string $index_name The index to drop.
     */
    protected function drop_index(string $table_name, string $index_name): void
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- migration rollback; no dbDelta/WP_Query equivalent exists for dropping an index. See ARCHITECTURE.md §2.5, §3, §11.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/index identifiers only, derived from $wpdb->prefix and this migration's own literal index names, never user input; identifiers cannot be bound via wpdb::prepare().
        $this->db()->query("ALTER TABLE {$table_name} DROP INDEX {$index_name}");
    }
}
