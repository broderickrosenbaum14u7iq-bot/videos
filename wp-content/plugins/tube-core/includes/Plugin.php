<?php
/**
 * Tube Core's bootstrap.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core;

use Tube_Core\CLI\MigrateCommand;
use Tube_Core\Content\CategoryTaxonomy;
use Tube_Core\Content\TagTaxonomy;
use Tube_Core\Content\VideoPostType;
use Tube_Core\Database\SchemaVersionStore;
use Tube_Core\Migration\MigrationRunner;
use Tube_Core\Migrations\Migration001CreateVideoMetadataTable;
use Tube_Core\Migrations\Migration002CreateActorTables;
use Tube_Core\Migrations\Migration003CreateStudioTables;
use WP_CLI;

/**
 * Tube Core's bootstrap: wires content registration and the migration
 * runner into WordPress.
 *
 * Content classes (VideoPostType, CategoryTaxonomy, TagTaxonomy) do not
 * hook themselves — they expose a single public registration method and
 * are wired to `init` here, so each can be instantiated and called
 * directly in isolation for testing, without triggering WordPress's hook
 * machinery.
 */
final class Plugin
{
    /**
     * The shared instance, lazily created by self::instance().
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Lazily created by self::migration_runner().
     *
     * @var MigrationRunner|null
     */
    private ?MigrationRunner $migration_runner = null;

    /**
     * Private: use self::instance() instead.
     */
    private function __construct()
    {
    }

    /**
     * The shared Plugin instance.
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Wire up hooks. Called on `plugins_loaded`.
     */
    public function boot(): void
    {
        $video_post_type   = new VideoPostType();
        $category_taxonomy = new CategoryTaxonomy();
        $tag_taxonomy      = new TagTaxonomy();

        add_action('init', [$video_post_type, 'register_post_type']);
        add_action('init', [$category_taxonomy, 'register_taxonomy']);
        add_action('init', [$tag_taxonomy, 'register_taxonomy']);

        $this->register_cli_commands();
    }

    /**
     * Plugin activation: register content types synchronously (so their
     * rewrite rules exist before the flush below, rather than waiting for
     * the next natural `init`), install the schema-version tracking table,
     * apply every pending tube-core migration, then flush rewrite rules.
     */
    public static function activate(): void
    {
        (new VideoPostType())->register_post_type();
        (new CategoryTaxonomy())->register_taxonomy();
        (new TagTaxonomy())->register_taxonomy();

        SchemaVersionStore::install();
        self::instance()->migration_runner()->migrate_up('tube-core');

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation.
     *
     * Deliberately non-destructive: deactivation never drops tables or
     * rolls back migrations. An explicit `wp tube migrate down` (or a
     * future, separately-invoked uninstall routine) is the only
     * destructive path, per ARCHITECTURE.md §18.3.
     */
    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    /**
     * The MigrationRunner, with tube-core's own migrations registered.
     *
     * Public so other tube-* plugins can register their own migration
     * sets against the same runner once they have any (from Phase 7
     * onward, per ARCHITECTURE.md §3's shared-migration-engine design).
     */
    public function migration_runner(): MigrationRunner
    {
        if (null === $this->migration_runner) {
            $this->migration_runner = new MigrationRunner(new SchemaVersionStore());
            $this->migration_runner->register_source(
                'tube-core',
                [
                    Migration001CreateVideoMetadataTable::class,
                    Migration002CreateActorTables::class,
                    Migration003CreateStudioTables::class,
                ]
            );
        }

        return $this->migration_runner;
    }

    /**
     * Register `wp tube migrate` when running under WP-CLI.
     */
    private function register_cli_commands(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        WP_CLI::add_command('tube migrate', new MigrateCommand($this->migration_runner()));
    }
}
