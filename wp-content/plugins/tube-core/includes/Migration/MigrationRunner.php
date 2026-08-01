<?php
/**
 * Applies and rolls back schema migrations.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Migration;

use InvalidArgumentException;

/**
 * Applies and rolls back schema migrations, tracked per plugin in
 * wp_tube_schema_versions.
 *
 * Plugins self-register their ordered migration set via
 * register_source() rather than being discovered by scanning the
 * filesystem — this keeps discovery explicit and type-safe (an invalid
 * class name fails fast at parse time) instead of relying on filename
 * conventions, per ARCHITECTURE.md §3's "every plugin supports schema
 * migrations" contract.
 */
final class MigrationRunner
{
    /**
     * Each registered plugin's ordered migration classes, keyed by plugin slug.
     *
     * @var array<string, list<class-string<MigrationInterface>>>
     */
    private array $sources = [];

    /**
     * Construct the runner around its version repository.
     *
     * @param SchemaVersionRepositoryInterface $versions Where applied versions are read from and recorded to.
     */
    public function __construct(private readonly SchemaVersionRepositoryInterface $versions)
    {
    }

    /**
     * Register a plugin's ordered list of migration classes.
     *
     * @param string $plugin_slug       Identity used in wp_tube_schema_versions.
     * @param array  $migration_classes Fully-qualified migration class names, in apply order.
     *
     * @phpstan-param list<class-string<MigrationInterface>> $migration_classes
     */
    public function register_source(string $plugin_slug, array $migration_classes): void
    {
        $this->sources[ $plugin_slug ] = $migration_classes;
    }

    /**
     * The plugin slugs that currently have a registered migration set.
     *
     * @return list<string>
     */
    public function registered_plugins(): array
    {
        return array_keys($this->sources);
    }

    /**
     * The status of every registered migration, across every plugin.
     *
     * @return list<array{plugin_slug: string, version: string, description: string,
     *     applied: bool, applied_at: ?string}>
     */
    public function status(): array
    {
        $rows = [];

        foreach ($this->sources as $plugin_slug => $migration_classes) {
            $applied = $this->versions->applied_versions($plugin_slug);

            foreach ($migration_classes as $migration_class) {
                $migration = $this->instantiate($migration_class);
                $version   = $migration->version();

                $rows[] = [
                    'plugin_slug' => $plugin_slug,
                    'version'     => $version,
                    'description' => $migration->description(),
                    'applied'     => isset($applied[ $version ]),
                    'applied_at'  => $applied[ $version ] ?? null,
                ];
            }
        }

        return $rows;
    }

    /**
     * Apply pending migrations.
     *
     * @param string|null $plugin_slug Limit to one plugin, or null for every registered source.
     * @param string|null $to_version  Stop after applying this version (inclusive), or null for everything pending.
     *
     * @return list<array{plugin_slug: string, version: string}> Migrations that were applied, in order.
     *
     * @throws InvalidArgumentException If $plugin_slug is given but nothing is registered for it.
     */
    public function migrate_up(?string $plugin_slug = null, ?string $to_version = null): array
    {
        if (null !== $plugin_slug && ! isset($this->sources[ $plugin_slug ])) {
            throw new InvalidArgumentException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception message, never rendered as HTML output.
                sprintf('No migrations are registered for plugin "%s".', $plugin_slug)
            );
        }

        $applied_now = [];

        foreach ($this->sources as $slug => $migration_classes) {
            if (null !== $plugin_slug && $slug !== $plugin_slug) {
                continue;
            }

            $already_applied = $this->versions->applied_versions($slug);

            foreach ($migration_classes as $migration_class) {
                $migration = $this->instantiate($migration_class);
                $version   = $migration->version();

                if (! isset($already_applied[ $version ])) {
                    $migration->up();
                    $this->versions->record($slug, $version);

                    $applied_now[] = [
                        'plugin_slug' => $slug,
                        'version'     => $version,
                    ];
                }

                if (null !== $to_version && $to_version === $version) {
                    break;
                }
            }
        }

        return $applied_now;
    }

    /**
     * Roll a single plugin's schema back to (but not including) $to_version.
     *
     * Every applied migration registered after $to_version is reverted,
     * most-recent first; $to_version itself stays applied.
     *
     * @param string $plugin_slug The plugin to roll back. Never spans multiple plugins at once.
     * @param string $to_version  Target version to roll back to.
     *
     * @return list<array{plugin_slug: string, version: string}> Migrations rolled back, most-recent first.
     *
     * @throws InvalidArgumentException If nothing is registered for $plugin_slug, or $to_version
     *                                  is not one of its registered versions.
     */
    public function migrate_down(string $plugin_slug, string $to_version): array
    {
        if (! isset($this->sources[ $plugin_slug ])) {
            throw new InvalidArgumentException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception message, never rendered as HTML output.
                sprintf('No migrations are registered for plugin "%s".', $plugin_slug)
            );
        }

        $migration_classes = $this->sources[ $plugin_slug ];
        $versions_in_order = array_map(
            fn (string $migration_class): string => $this->instantiate($migration_class)->version(),
            $migration_classes
        );

        if (! in_array($to_version, $versions_in_order, true)) {
            throw new InvalidArgumentException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception message, never rendered as HTML output.
                sprintf('"%s" is not a registered migration version for plugin "%s".', $to_version, $plugin_slug)
            );
        }

        $already_applied = $this->versions->applied_versions($plugin_slug);
        $rolled_back     = [];

        foreach (array_reverse($migration_classes) as $migration_class) {
            $migration = $this->instantiate($migration_class);
            $version   = $migration->version();

            if ($to_version === $version) {
                break;
            }

            if (isset($already_applied[ $version ])) {
                $migration->down();
                $this->versions->forget($plugin_slug, $version);

                $rolled_back[] = [
                    'plugin_slug' => $plugin_slug,
                    'version'     => $version,
                ];
            }
        }

        return $rolled_back;
    }

    /**
     * Instantiate a migration class.
     *
     * @param string $migration_class The migration class to instantiate.
     *
     * @phpstan-param class-string<MigrationInterface> $migration_class
     */
    private function instantiate(string $migration_class): MigrationInterface
    {
        return new $migration_class();
    }
}
