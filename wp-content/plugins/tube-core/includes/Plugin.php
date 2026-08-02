<?php
/**
 * Tube Core's bootstrap.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core;

use Predis\Client;
use Tube_Core\CLI\MigrateCommand;
use Tube_Core\CLI\ViewsCommand;
use Tube_Core\Content\CategoryTaxonomy;
use Tube_Core\Content\TagTaxonomy;
use Tube_Core\Content\VideoPostType;
use Tube_Core\Database\SchemaVersionStore;
use Tube_Core\Events\Dispatcher;
use Tube_Core\Events\VideoLifecycleEvents;
use Tube_Core\Events\WordPressHookBus;
use Tube_Core\Migration\MigrationRunner;
use Tube_Core\SchemaMigrations\Migration001CreateVideoMetadataTable;
use Tube_Core\SchemaMigrations\Migration002CreateActorTables;
use Tube_Core\SchemaMigrations\Migration003CreateStudioTables;
use Tube_Core\SchemaMigrations\Migration004AddActorStudioNameIndexes;
use Tube_Core\SchemaMigrations\Migration005CreateVideoViewsTable;
use Tube_Core\SchemaMigrations\Migration006CreateVideoStatisticsTable;
use Tube_Core\Views\RedisViewCounter;
use Tube_Core\Views\Repositories\VideoStatisticsRepository;
use Tube_Core\Views\Repositories\VideoViewsRepository;
use Tube_Core\Views\Retention;
use Tube_Core\Views\StatsRollup;
use Tube_Core\Views\ViewCounterInterface;
use Tube_Core\Views\ViewRecorder;
use Tube_Core\Views\ViewsFlusher;
use WP_CLI;

/**
 * Tube Core's bootstrap: wires content registration, the event
 * dispatcher, and the migration runner into WordPress.
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
     * Lazily created by self::events().
     *
     * @var Dispatcher|null
     */
    private ?Dispatcher $events = null;

    /**
     * Lazily created by self::view_counter().
     *
     * @var ViewCounterInterface|null
     */
    private ?ViewCounterInterface $view_counter = null;

    /**
     * Lazily created by self::view_recorder().
     *
     * @var ViewRecorder|null
     */
    private ?ViewRecorder $view_recorder = null;

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

        (new VideoLifecycleEvents($this->events()))->register();

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
                    Migration004AddActorStudioNameIndexes::class,
                    Migration005CreateVideoViewsTable::class,
                    Migration006CreateVideoStatisticsTable::class,
                ]
            );
        }

        return $this->migration_runner;
    }

    /**
     * The event Dispatcher, backed by WordPress's action hook mechanism.
     *
     * Public so other tube-* plugins can dispatch or listen for events
     * once they exist (from Phase 3 onward, per ARCHITECTURE.md §6),
     * the same way self::migration_runner() is exposed for their
     * migration sets.
     */
    public function events(): Dispatcher
    {
        if (null === $this->events) {
            $this->events = new Dispatcher(new WordPressHookBus());
        }

        return $this->events;
    }

    /**
     * The Redis-buffered view counter, per ARCHITECTURE.md §12 Phase 4.
     *
     * Not exposed to other plugins the way self::events()/
     * self::migration_runner() are — nothing outside tube-core needs to
     * touch the buffer directly; self::view_recorder() is the public
     * entry point a future consumer (a REST controller, tube-player)
     * calls instead.
     */
    private function view_counter(): ViewCounterInterface
    {
        if (null === $this->view_counter) {
            $host = defined('TUBE_CORE_REDIS_HOST') ? TUBE_CORE_REDIS_HOST : '127.0.0.1';
            $port = defined('TUBE_CORE_REDIS_PORT') ? TUBE_CORE_REDIS_PORT : 6379;

            $this->view_counter = new RedisViewCounter(
                new Client(
                    [
                        'host' => $host,
                        'port' => $port,
                    ]
                )
            );
        }

        return $this->view_counter;
    }

    /**
     * Records a view (buffers it, dispatches VIDEO_VIEW_RECORDED), per
     * ARCHITECTURE.md §12 Phase 4.
     *
     * Public so a future consumer (a REST controller, tube-player) can
     * call `Plugin::instance()->view_recorder()->record($video_id)` —
     * the same "public accessor for a cross-cutting concern" shape as
     * self::events()/self::migration_runner().
     */
    public function view_recorder(): ViewRecorder
    {
        if (null === $this->view_recorder) {
            $this->view_recorder = new ViewRecorder($this->view_counter(), $this->events());
        }

        return $this->view_recorder;
    }

    /**
     * Register `wp tube migrate` and `wp tube-core views:flush`/
     * `stats:rollup`/`views:partition-maintenance` when running under WP-CLI.
     */
    private function register_cli_commands(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        WP_CLI::add_command('tube migrate', new MigrateCommand($this->migration_runner()));

        $views_repository      = new VideoViewsRepository();
        $statistics_repository = new VideoStatisticsRepository();

        $views_command = new ViewsCommand(
            new ViewsFlusher($this->view_counter(), $views_repository, $statistics_repository),
            new StatsRollup($views_repository, $statistics_repository, $this->events()),
            new Retention($views_repository)
        );

        // Registered as three individually-named commands, not one class
        // with WP-CLI's usual space-separated subcommands — see
        // ViewsCommand's own docblock for why.
        WP_CLI::add_command('tube-core views:flush', [$views_command, 'flush']);
        WP_CLI::add_command('tube-core stats:rollup', [$views_command, 'rollup']);
        WP_CLI::add_command('tube-core views:partition-maintenance', [$views_command, 'partition_maintenance']);
    }
}
