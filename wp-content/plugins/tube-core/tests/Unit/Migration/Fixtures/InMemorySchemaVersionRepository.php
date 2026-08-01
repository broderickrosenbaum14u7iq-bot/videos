<?php
/**
 * Test fixture: an in-memory SchemaVersionRepositoryInterface.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Migration\Fixtures;

use Tube_Core\Migration\SchemaVersionRepositoryInterface;

/**
 * An in-memory SchemaVersionRepositoryInterface, used in place of
 * Tube_Core\Database\SchemaVersionStore so MigrationRunner's orchestration
 * logic can be unit-tested without a database.
 */
final class InMemorySchemaVersionRepository implements SchemaVersionRepositoryInterface
{
    /**
     * Applied versions, per plugin slug.
     *
     * @var array<string, array<string, string>>
     */
    private array $applied = [];

    /**
     * {@inheritDoc}
     *
     * @param string $plugin_slug The plugin identity to look up.
     */
    public function applied_versions(string $plugin_slug): array
    {
        return $this->applied[ $plugin_slug ] ?? [];
    }

    /**
     * {@inheritDoc}
     *
     * @param string $plugin_slug The plugin identity to record against.
     * @param string $version     The migration version being recorded as applied.
     */
    public function record(string $plugin_slug, string $version): void
    {
        $this->applied[ $plugin_slug ][ $version ] = '2026-01-01 00:00:00';
    }

    /**
     * {@inheritDoc}
     *
     * @param string $plugin_slug The plugin identity to forget the version for.
     * @param string $version     The migration version to un-record.
     */
    public function forget(string $plugin_slug, string $version): void
    {
        unset($this->applied[ $plugin_slug ][ $version ]);
    }
}
