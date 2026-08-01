<?php
/**
 * Contract for reading/recording applied migration versions.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Migration;

/**
 * Contract for reading/recording which migration versions have been
 * applied, per plugin.
 *
 * MigrationRunner depends on this interface rather than a concrete
 * WordPress-backed implementation so its orchestration logic (ordering,
 * targeting a version, deciding what is pending) can be unit-tested
 * without a database, per the "every plugin must be independently
 * testable" rule. Tube_Core\Database\SchemaVersionStore is the real,
 * wp_tube_schema_versions-backed implementation used in production.
 */
interface SchemaVersionRepositoryInterface
{
    /**
     * The applied versions for one plugin, in ascending order.
     *
     * @param string $plugin_slug The plugin identity to look up.
     *
     * @return array<string, string> Map of version => applied_at (MySQL datetime string).
     */
    public function applied_versions(string $plugin_slug): array;

    /**
     * Record that a version has been applied.
     *
     * @param string $plugin_slug The plugin identity to record against.
     * @param string $version     The migration version being recorded as applied.
     */
    public function record(string $plugin_slug, string $version): void;

    /**
     * Remove the record of a version having been applied (used on rollback).
     *
     * @param string $plugin_slug The plugin identity to forget the version for.
     * @param string $version     The migration version to un-record.
     */
    public function forget(string $plugin_slug, string $version): void;
}
