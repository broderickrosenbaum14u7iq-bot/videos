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

    /**
     * Rename a column in place, preserving its data and type.
     *
     * Used from up()/down() methods that need to repurpose an existing
     * column's name (e.g. parking a superseded column under a new name
     * rather than dropping its data — see `Migration010SeparateLegacyCloudflareImageIds`).
     * Like drop_table()/drop_index(), dbDelta() has no equivalent — it
     * only ever adds what's missing when diffing a CREATE TABLE
     * statement, it never renames, so this is a direct query by
     * necessity. Requires MySQL 8.0+/MariaDB 10.5+'s `RENAME COLUMN`
     * syntax (this project targets MariaDB 11.4, ARCHITECTURE.md §11).
     *
     * @param string $table_name Full table name, including the site's table prefix.
     * @param string $from       The column's current name.
     * @param string $to         The column's new name.
     */
    protected function rename_column(string $table_name, string $from, string $to): void
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- schema change; no dbDelta/WP_Query equivalent exists for renaming a column. See ARCHITECTURE.md §2.5, §3, §11.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column identifiers only, derived from $wpdb->prefix and this migration's own literal column names, never user input; identifiers cannot be bound via wpdb::prepare().
        $this->db()->query("ALTER TABLE {$table_name} RENAME COLUMN {$from} TO {$to}");
    }

    /**
     * Drop a single column from a table.
     *
     * Used from down() methods that reverse a column-add change dbDelta()
     * applied in up() — dbDelta() adds columns but never removes them, so
     * removing one is a direct query by necessity, the same reasoning as
     * drop_table()/drop_index().
     *
     * @param string $table_name  Full table name, including the site's table prefix.
     * @param string $column_name The column to drop.
     */
    protected function drop_column(string $table_name, string $column_name): void
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- schema change; no dbDelta/WP_Query equivalent exists for dropping a column. See ARCHITECTURE.md §2.5, §3, §11.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column identifiers only, derived from $wpdb->prefix and this migration's own literal column names, never user input; identifiers cannot be bound via wpdb::prepare().
        $this->db()->query("ALTER TABLE {$table_name} DROP COLUMN {$column_name}");
    }

    /**
     * Change an existing column's definition in place (type, nullability,
     * default) without renaming it.
     *
     * Used from up()/down() methods that need to widen/narrow an existing
     * column's constraints (e.g. relaxing a column from `NOT NULL` to
     * nullable to support a second, optional data path — see
     * `Migration014AddVideoSourceAndR2Fields`). Like rename_column()/
     * drop_column(), dbDelta() has no equivalent — it only ever adds
     * what's missing when diffing a CREATE TABLE statement, it never
     * alters an existing column's own definition, so this is a direct
     * query by necessity.
     *
     * @param string $table_name            Full table name, including the site's table prefix.
     * @param string $column_definition_sql The column's full new definition, e.g. `"x VARCHAR(64) NULL"`.
     */
    protected function modify_column(string $table_name, string $column_definition_sql): void
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- schema change; no dbDelta/WP_Query equivalent exists for altering a column's definition. See ARCHITECTURE.md §2.5, §3, §11.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifier and column definition are this migration's own literal strings, never user input; a column definition cannot be bound via wpdb::prepare() either way.
        $this->db()->query("ALTER TABLE {$table_name} MODIFY COLUMN {$column_definition_sql}");
    }
}
