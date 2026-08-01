<?php
/**
 * `wp tube migrate` — apply, roll back, and inspect schema migrations.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\CLI;

use InvalidArgumentException;
use Tube_Core\Migration\MigrationRunner;
use WP_CLI;

use function WP_CLI\Utils\format_items;

/**
 * `wp tube migrate` — apply, roll back, and inspect schema migrations.
 *
 * Per ARCHITECTURE.md §3 and §18.4, schema migrations run only through
 * this command (during a deploy), never automatically at runtime beyond
 * plugin activation. WP-Cron is never used for this or anything else in
 * this project (ARCHITECTURE.md §7).
 */
final class MigrateCommand
{
    /**
     * Construct the command around the runner it drives.
     *
     * @param MigrationRunner $runner The runner this command drives.
     */
    public function __construct(private readonly MigrationRunner $runner)
    {
    }

    /**
     * Show the schema migration status for every registered tube-* plugin.
     *
     * ## EXAMPLES
     *
     *     wp tube migrate status
     *
     * @when after_wp_load
     */
    public function status(): void
    {
        $rows = $this->runner->status();

        if ([] === $rows) {
            WP_CLI::log('No migrations are registered.');

            return;
        }

        $formatted = array_map(
            static fn (array $row): array => [
                'plugin'      => $row['plugin_slug'],
                'version'     => $row['version'],
                'description' => $row['description'],
                'applied'     => $row['applied'] ? 'yes' : 'no',
                'applied_at'  => $row['applied_at'] ?? '-',
            ],
            $rows
        );

        format_items('table', $formatted, ['plugin', 'version', 'description', 'applied', 'applied_at']);
    }

    /**
     * Apply pending migrations.
     *
     * ## OPTIONS
     *
     * [--plugin=<slug>]
     * : Limit to a single plugin's migrations. Defaults to every registered plugin.
     *
     * [--to=<version>]
     * : Stop after applying this version. Only meaningful together with --plugin.
     *
     * ## EXAMPLES
     *
     *     wp tube migrate up
     *     wp tube migrate up --plugin=tube-core
     *     wp tube migrate up --plugin=tube-core --to=002
     *
     * @when after_wp_load
     *
     * @param array<int, string>    $args       Positional arguments (unused).
     * @param array<string, string> $assoc_args Associative arguments (--plugin, --to).
     */
    public function up(array $args, array $assoc_args): void
    {
        $plugin = $assoc_args['plugin'] ?? null;
        $to     = $assoc_args['to'] ?? null;

        try {
            $applied = $this->runner->migrate_up($plugin, $to);
        } catch (InvalidArgumentException $exception) {
            WP_CLI::error($exception->getMessage());

            return;
        }

        if ([] === $applied) {
            WP_CLI::success('Nothing to migrate — already up to date.');

            return;
        }

        foreach ($applied as $migration) {
            WP_CLI::log(sprintf('Applied %s: %s', $migration['plugin_slug'], $migration['version']));
        }

        WP_CLI::success(sprintf('Applied %d migration(s).', count($applied)));
    }

    /**
     * Roll a single plugin's schema back to an earlier version.
     *
     * ## OPTIONS
     *
     * --plugin=<slug>
     * : The plugin to roll back. Required — rollback never spans multiple plugins at once.
     *
     * --to=<version>
     * : Target version to roll back to. Every applied migration registered after this version is reverted.
     *
     * ## EXAMPLES
     *
     *     wp tube migrate down --plugin=tube-core --to=001
     *
     * @when after_wp_load
     *
     * @param array<int, string>    $args       Positional arguments (unused).
     * @param array<string, string> $assoc_args Associative arguments (--plugin, --to).
     */
    public function down(array $args, array $assoc_args): void
    {
        if (! isset($assoc_args['plugin'], $assoc_args['to'])) {
            WP_CLI::error('Both --plugin and --to are required for rollback.');

            return;
        }

        try {
            $rolled_back = $this->runner->migrate_down($assoc_args['plugin'], $assoc_args['to']);
        } catch (InvalidArgumentException $exception) {
            WP_CLI::error($exception->getMessage());

            return;
        }

        if ([] === $rolled_back) {
            WP_CLI::success('Nothing to roll back.');

            return;
        }

        foreach ($rolled_back as $migration) {
            WP_CLI::log(sprintf('Rolled back %s: %s', $migration['plugin_slug'], $migration['version']));
        }

        WP_CLI::success(sprintf('Rolled back %d migration(s).', count($rolled_back)));
    }
}
