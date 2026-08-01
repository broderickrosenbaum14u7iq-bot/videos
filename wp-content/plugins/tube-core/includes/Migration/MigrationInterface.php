<?php
/**
 * Contract every schema migration must implement.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Migration;

/**
 * Contract every schema migration must implement.
 *
 * Per ARCHITECTURE.md §3, every tube-* plugin follows this same
 * up()/down() contract for schema changes, tracked centrally in
 * wp_tube_schema_versions by MigrationRunner. Rollback is a first-class
 * operation: down() must reverse up() exactly.
 */
interface MigrationInterface
{
    /**
     * The version identifier for this migration.
     *
     * Unique within the migration set of a single plugin. Migrations for
     * a given plugin are applied in the order they are registered with
     * MigrationRunner::register_source(), not by sorting this value —
     * this method identifies a version for tracking/targeting purposes
     * (e.g. `wp tube migrate down --to=001`), it does not determine order.
     */
    public function version(): string;

    /**
     * A short, human-readable description shown by `wp tube migrate status`.
     */
    public function description(): string;

    /**
     * Apply this migration's schema change.
     */
    public function up(): void;

    /**
     * Reverse this migration's schema change exactly.
     *
     * Must undo everything up() did (dropping tables/columns it created,
     * restoring what it altered) so that `wp tube migrate down` is safe
     * to run, per ARCHITECTURE.md §3 and §18.3.
     */
    public function down(): void;
}
