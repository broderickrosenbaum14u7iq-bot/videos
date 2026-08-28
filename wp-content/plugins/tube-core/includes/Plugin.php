<?php
/**
 * Tube Core's bootstrap.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core;

use Predis\Client;
use Tube_Core\CLI\ImportCommand;
use Tube_Core\CLI\MigrateCommand;
use Tube_Core\CLI\StreamCommand;
use Tube_Core\CLI\ViewsCommand;
use Tube_Core\CLI\WatchHistoryCommand;
use Tube_Core\Content\Actor;
use Tube_Core\Content\CategoryTaxonomy;
use Tube_Core\Content\Repositories\ActorRepository;
use Tube_Core\Content\Repositories\ActorRepositoryInterface;
use Tube_Core\Content\Repositories\StudioRepository;
use Tube_Core\Content\Repositories\StudioRepositoryInterface;
use Tube_Core\Content\Routing\TermArchiveRouting;
use Tube_Core\Content\Studio;
use Tube_Core\Content\TagTaxonomy;
use Tube_Core\Content\VideoPostType;
use Tube_Core\Database\SchemaVersionStore;
use Tube_Core\Engagement\GuestMergeService;
use Tube_Core\Events\Dispatcher;
use Tube_Core\Events\VideoLifecycleEvents;
use Tube_Core\Events\WordPressHookBus;
use Tube_Core\Import\BatchProcessor;
use Tube_Core\Import\Repositories\ImportQueueRepository;
use Tube_Core\Import\VideoImporter;
use Tube_Core\Likes\LikeController;
use Tube_Core\Likes\LikeToggleService;
use Tube_Core\Likes\Repositories\LikeRepository;
use Tube_Core\Likes\Repositories\LikeRepositoryInterface;
use Tube_Core\Migration\MigrationRunner;
use Tube_Core\SchemaMigrations\Migration001CreateVideoMetadataTable;
use Tube_Core\SchemaMigrations\Migration002CreateActorTables;
use Tube_Core\SchemaMigrations\Migration003CreateStudioTables;
use Tube_Core\SchemaMigrations\Migration004AddActorStudioNameIndexes;
use Tube_Core\SchemaMigrations\Migration005CreateVideoViewsTable;
use Tube_Core\SchemaMigrations\Migration006CreateVideoStatisticsTable;
use Tube_Core\SchemaMigrations\Migration007CreateImportQueueTable;
use Tube_Core\SchemaMigrations\Migration008CreateWatchHistoryTable;
use Tube_Core\SchemaMigrations\Migration009AddVideoStatisticsWindowIndexes;
use Tube_Core\SchemaMigrations\Migration010SeparateLegacyCloudflareImageIds;
use Tube_Core\SchemaMigrations\Migration011CreateVideoLikesTable;
use Tube_Core\SchemaMigrations\Migration012AddLikesTotalToVideoStatistics;
use Tube_Core\SchemaMigrations\Migration013CreateSavedVideosTable;
use Tube_Core\Saves\Repositories\SavedVideoRepository;
use Tube_Core\Saves\Repositories\SavedVideoRepositoryInterface;
use Tube_Core\Saves\SaveController;
use Tube_Core\Saves\SaveToggleService;
use Tube_Core\Stream\CloudflareStreamDetailsFetcher;
use Tube_Core\Stream\StreamDetailsProviderInterface;
use Tube_Core\Stream\StreamMetadataSyncer;
use Tube_Core\Stream\StreamStatusUpdater;
use Tube_Core\Stream\WebhookController;
use Tube_Core\Stream\WebhookSignatureVerifier;
use Tube_Core\Support\RedisRateLimiter;
use Tube_Core\Video\Repositories\VideoMetadataRepository;
use Tube_Core\Video\Repositories\VideoMetadataRepositoryInterface;
use Tube_Core\Views\RedisViewCounter;
use Tube_Core\Views\Repositories\VideoStatisticsRepository;
use Tube_Core\Views\Repositories\VideoStatisticsRepositoryInterface;
use Tube_Core\Views\Repositories\VideoViewsRepository;
use Tube_Core\Views\Retention;
use Tube_Core\Views\StatsRollup;
use Tube_Core\Views\ViewBaselineSubscriber;
use Tube_Core\Views\ViewController;
use Tube_Core\Views\ViewCounterInterface;
use Tube_Core\Views\ViewRecorder;
use Tube_Core\Views\ViewsFlusher;
use Tube_Core\WatchHistory\GuestHistoryRetention;
use Tube_Core\WatchHistory\Repositories\WatchHistoryRepository;
use Tube_Core\WatchHistory\VisitorToken;
use Tube_Core\WatchHistory\WatchHistoryController;
use Tube_Core\WatchHistory\WatchHistoryRecorder;
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
     * Lazily created by self::video_metadata_repository().
     *
     * @var VideoMetadataRepositoryInterface|null
     */
    private ?VideoMetadataRepositoryInterface $video_metadata_repository = null;

    /**
     * Lazily created by self::stream_details_provider().
     *
     * @var StreamDetailsProviderInterface|null
     */
    private ?StreamDetailsProviderInterface $stream_details_provider = null;

    /**
     * Lazily created by self::stream_metadata_syncer().
     *
     * @var StreamMetadataSyncer|null
     */
    private ?StreamMetadataSyncer $stream_metadata_syncer = null;

    /**
     * Lazily created by self::video_statistics_repository().
     *
     * @var VideoStatisticsRepositoryInterface|null
     */
    private ?VideoStatisticsRepositoryInterface $video_statistics_repository = null;

    /**
     * Lazily created by self::actor_repository().
     *
     * @var ActorRepositoryInterface|null
     */
    private ?ActorRepositoryInterface $actor_repository = null;

    /**
     * Lazily created by self::studio_repository().
     *
     * @var StudioRepositoryInterface|null
     */
    private ?StudioRepositoryInterface $studio_repository = null;

    /**
     * Lazily created by self::like_repository().
     *
     * @var LikeRepositoryInterface|null
     */
    private ?LikeRepositoryInterface $like_repository = null;

    /**
     * Lazily created by self::saved_video_repository().
     *
     * @var SavedVideoRepositoryInterface|null
     */
    private ?SavedVideoRepositoryInterface $saved_video_repository = null;

    /**
     * Lazily created by self::rate_limiter().
     *
     * @var RedisRateLimiter|null
     */
    private ?RedisRateLimiter $rate_limiter = null;

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

        $actor_routing  = new TermArchiveRouting(
            'actor',
            'tube_actor',
            'archive-actor.php',
            fn (string $slug): ?Actor => $this->actor_repository()->find_by_slug($slug)
        );
        $studio_routing = new TermArchiveRouting(
            'studio',
            'tube_studio',
            'archive-studio.php',
            fn (string $slug): ?Studio => $this->studio_repository()->find_by_slug($slug)
        );

        add_action('init', [$actor_routing, 'add_rewrite_rules']);
        add_action('init', [$studio_routing, 'add_rewrite_rules']);
        add_filter('query_vars', [$actor_routing, 'register_query_var']);
        add_filter('query_vars', [$studio_routing, 'register_query_var']);
        add_filter('template_include', [$actor_routing, 'route_template']);
        add_filter('template_include', [$studio_routing, 'route_template']);

        (new VideoLifecycleEvents($this->events()))->register();
        (new ViewBaselineSubscriber($this->video_statistics_repository()))->register();

        add_action('rest_api_init', [$this, 'register_rest_routes']);

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

        // The finder closure is never invoked by add_rewrite_rules() itself
        // -- only route_template() (wired on init, not called here) uses it.
        // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required by the constructor's Closure signature; add_rewrite_rules() never invokes it.
        $unused_finder = static function (string $slug): ?object {
            return null;
        };

        (new TermArchiveRouting('actor', 'tube_actor', 'archive-actor.php', $unused_finder))->add_rewrite_rules();
        (new TermArchiveRouting('studio', 'tube_studio', 'archive-studio.php', $unused_finder))->add_rewrite_rules();

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
                    Migration007CreateImportQueueTable::class,
                    Migration008CreateWatchHistoryTable::class,
                    Migration009AddVideoStatisticsWindowIndexes::class,
                    Migration010SeparateLegacyCloudflareImageIds::class,
                    Migration011CreateVideoLikesTable::class,
                    Migration012AddLikesTotalToVideoStatistics::class,
                    Migration013CreateSavedVideosTable::class,
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
     * A Predis client against this environment's configured Redis —
     * shared by self::view_counter() and self::rate_limiter(), the two
     * real consumers that justify re-extracting this (ARCHITECTURE.md's
     * "extract a shared accessor only once 2+ real consumers exist"
     * rule — a single Predis client per request is enough; there is no
     * reason for self::view_counter() and self::rate_limiter() to each
     * open their own connection).
     */
    private function redis_client(): Client
    {
        $host = defined('TUBE_CORE_REDIS_HOST') ? TUBE_CORE_REDIS_HOST : '127.0.0.1';
        $port = defined('TUBE_CORE_REDIS_PORT') ? TUBE_CORE_REDIS_PORT : 6379;

        return new Client(
            [
                'host' => $host,
                'port' => $port,
            ]
        );
    }

    /**
     * The Redis-buffered view counter, per ARCHITECTURE.md §12 Phase 4.
     *
     * Not exposed to other plugins the way self::events()/
     * self::migration_runner() are — nothing outside tube-core needs to
     * touch the buffer directly; self::view_recorder() is the public
     * entry point `Tube_Core\Views\ViewController` calls instead.
     */
    private function view_counter(): ViewCounterInterface
    {
        if (null === $this->view_counter) {
            $this->view_counter = new RedisViewCounter($this->redis_client());
        }

        return $this->view_counter;
    }

    /**
     * The Redis-backed rate limiter `Tube_Core\Likes\LikeController`/
     * `Tube_Core\Saves\SaveController` use to bound how often one viewer
     * may toggle a like/save, per the mobile watch-page redesign's "rate
     * limiting where appropriate" requirement. Not exposed publicly —
     * only this plugin's own REST route registration constructs
     * controllers that need it.
     */
    private function rate_limiter(): RedisRateLimiter
    {
        if (null === $this->rate_limiter) {
            $this->rate_limiter = new RedisRateLimiter($this->redis_client());
        }

        return $this->rate_limiter;
    }

    /**
     * Records a view (buffers it, dispatches VIDEO_VIEW_RECORDED), per
     * ARCHITECTURE.md §12 Phase 4.
     *
     * Public so `Tube_Core\Views\ViewController` can call
     * `Plugin::instance()->view_recorder()->record($video_id)` — the
     * same "public accessor for a cross-cutting concern" shape as
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
     * The video-metadata repository, per ARCHITECTURE.md §12 Phase 5.
     *
     * Public as of Phase 6: `tube-player`'s `includes/template-tags.php`
     * calls `self::find()` here directly to read a video's Stream UID/
     * duration/thumbnail offset/image overrides for rendering — the same
     * "public accessor for a cross-cutting concern" shape as
     * self::events()/self::view_recorder(), now exercised by a real
     * cross-plugin consumer for the first time.
     */
    public function video_metadata_repository(): VideoMetadataRepositoryInterface
    {
        if (null === $this->video_metadata_repository) {
            $this->video_metadata_repository = new VideoMetadataRepository();
        }

        return $this->video_metadata_repository;
    }

    /**
     * The live Cloudflare Stream details lookup (status/duration by UID)
     * — the pull-based counterpart to the webhook's push, per
     * `StreamDetailsProviderInterface`'s own docblock.
     *
     * Public so `self::stream_metadata_syncer()`'s construction is
     * visible here at the composition root, and so tests/future
     * consumers can swap it.
     */
    public function stream_details_provider(): StreamDetailsProviderInterface
    {
        if (null === $this->stream_details_provider) {
            $account_id = defined('TUBE_CORE_CLOUDFLARE_STREAM_ACCOUNT_ID')
                && is_string(TUBE_CORE_CLOUDFLARE_STREAM_ACCOUNT_ID)
                ? TUBE_CORE_CLOUDFLARE_STREAM_ACCOUNT_ID
                : '';
            $api_token  = defined('TUBE_CORE_CLOUDFLARE_STREAM_API_TOKEN')
                && is_string(TUBE_CORE_CLOUDFLARE_STREAM_API_TOKEN)
                ? TUBE_CORE_CLOUDFLARE_STREAM_API_TOKEN
                : '';

            $this->stream_details_provider = new CloudflareStreamDetailsFetcher($account_id, $api_token);
        }

        return $this->stream_details_provider;
    }

    /**
     * The Cloudflare Stream status/duration synchronizer.
     *
     * Public: `Tube_Admin\Video\StreamUidMetaBox` calls this after a
     * manually-entered Stream UID is created/changed, so a video never
     * needs an actual webhook delivery (which only ever fires for videos
     * this project's own import pipeline uploaded) to get a real
     * duration. Composes its own `StreamStatusUpdater` — the same class
     * the webhook path uses — rather than writing to
     * `video_metadata_repository()` directly, specifically so a
     * successful sync also dispatches `EventCatalog::VIDEO_STREAM_STATUS_CHANGED`
     * (tube-search's index sync, tube-cache's purge subscriber) exactly
     * as a webhook-driven update already does; see `StreamMetadataSyncer`'s
     * own docblock.
     */
    public function stream_metadata_syncer(): StreamMetadataSyncer
    {
        if (null === $this->stream_metadata_syncer) {
            $this->stream_metadata_syncer = new StreamMetadataSyncer(
                $this->stream_details_provider(),
                new StreamStatusUpdater($this->video_metadata_repository(), $this->events())
            );
        }

        return $this->stream_metadata_syncer;
    }

    /**
     * The video-statistics repository, per ARCHITECTURE.md §12 Phase 4.
     *
     * Public as of Phase 7: `tube-search`'s own
     * `Tube_Search\Discovery\TubeCorePopularityRepository` calls
     * `self::top_by_views_total()`/`self::top_by_views_7d()` here for
     * "Most Viewed"/"Trending" — reading tube-core's precomputed
     * statistics table through its own repository API rather than
     * querying `wp_tube_video_statistics` directly, per the "no plugin
     * queries another plugin's tables" rule (`DEVELOPMENT_RULES.md` §6.8).
     */
    public function video_statistics_repository(): VideoStatisticsRepositoryInterface
    {
        if (null === $this->video_statistics_repository) {
            $this->video_statistics_repository = new VideoStatisticsRepository();
        }

        return $this->video_statistics_repository;
    }

    /**
     * The actor repository, per ARCHITECTURE.md §14/§12 Phase 8.
     *
     * Public: `tube-search`'s `VideoIndexer` calls
     * `self::actor_ids_for_video()` to keep the search index's
     * `actor_ids` column in sync; `includes/template-tags.php`'s
     * `tube_core_get_actor_by_slug()` is a thin wrapper around
     * `self::find_by_slug()`.
     */
    public function actor_repository(): ActorRepositoryInterface
    {
        if (null === $this->actor_repository) {
            $this->actor_repository = new ActorRepository();
        }

        return $this->actor_repository;
    }

    /**
     * The studio repository, per ARCHITECTURE.md §14/§12 Phase 8. Same shape as self::actor_repository().
     */
    public function studio_repository(): StudioRepositoryInterface
    {
        if (null === $this->studio_repository) {
            $this->studio_repository = new StudioRepository();
        }

        return $this->studio_repository;
    }

    /**
     * The like-rows repository, per the mobile watch-page redesign's
     * real Like system.
     *
     * Public: `includes/template-tags.php`'s `tube_core_has_liked()`
     * calls `self::has_liked()` here directly to resolve a video's
     * initial "already liked" state at page-render time — the same
     * "public accessor for a cross-cutting concern" shape as
     * self::video_metadata_repository().
     */
    public function like_repository(): LikeRepositoryInterface
    {
        if (null === $this->like_repository) {
            $this->like_repository = new LikeRepository();
        }

        return $this->like_repository;
    }

    /**
     * The saved-video-rows ("Watch Later") repository. Public for the
     * same reason as self::like_repository() —
     * `tube_core_has_saved()` reads it directly.
     */
    public function saved_video_repository(): SavedVideoRepositoryInterface
    {
        if (null === $this->saved_video_repository) {
            $this->saved_video_repository = new SavedVideoRepository();
        }

        return $this->saved_video_repository;
    }

    /**
     * Merge a guest visitor token's likes/saves into a newly-
     * authenticated member's own likes/saves, per the member system's
     * Phase 26/27. Public so `tube-members` can call this exactly once,
     * immediately after a successful login/registration/Google sign-in
     * — the same "public accessor for a cross-cutting concern" shape as
     * self::like_repository()/self::saved_video_repository(), now
     * exercised by tube-members as the first real cross-plugin *write*
     * consumer (tube-search's own cross-plugin calls are read-only).
     *
     * @param string $visitor_token The guest's `tube_visitor` cookie value being merged away.
     * @param int    $user_id       The member account absorbing the guest's engagement.
     */
    public function merge_guest_engagement_into_user(string $visitor_token, int $user_id): void
    {
        (new GuestMergeService(
            $this->like_repository(),
            $this->saved_video_repository(),
            $this->video_statistics_repository()
        ))->merge($visitor_token, $user_id);
    }

    /**
     * Register `tube/v1` REST routes. Called on `rest_api_init`.
     *
     * Public only because WordPress's hook mechanism requires it (the
     * same reason self::boot() is public) — not part of this class's
     * intended external API the way self::events()/
     * self::migration_runner()/self::view_recorder() are.
     */
    public function register_rest_routes(): void
    {
        $webhook_controller = new WebhookController(
            new WebhookSignatureVerifier(),
            new StreamStatusUpdater($this->video_metadata_repository(), $this->events())
        );

        register_rest_route(
            'tube/v1',
            '/webhooks/cloudflare-stream',
            [
                'methods'             => 'POST',
                'callback'            => [$webhook_controller, 'handle'],
                'permission_callback' => [$webhook_controller, 'check_signature'],
            ]
        );

        $watch_history_controller = new WatchHistoryController(
            new WatchHistoryRecorder(new WatchHistoryRepository()),
            new VisitorToken()
        );

        register_rest_route(
            'tube/v1',
            '/videos/(?P<id>\d+)/watch-history',
            [
                'methods'             => 'POST',
                'callback'            => [$watch_history_controller, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );

        $view_controller = new ViewController($this->view_recorder());

        register_rest_route(
            'tube/v1',
            '/videos/(?P<id>\d+)/view',
            [
                'methods'             => 'POST',
                'callback'            => [$view_controller, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );

        $like_controller = new LikeController(
            new LikeToggleService($this->like_repository(), $this->video_statistics_repository()),
            new VisitorToken(),
            $this->rate_limiter()
        );

        register_rest_route(
            'tube/v1',
            '/videos/(?P<id>\d+)/like',
            [
                'methods'             => 'POST',
                'callback'            => [$like_controller, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );

        $save_controller = new SaveController(
            new SaveToggleService($this->saved_video_repository()),
            new VisitorToken(),
            $this->rate_limiter()
        );

        register_rest_route(
            'tube/v1',
            '/videos/(?P<id>\d+)/save',
            [
                'methods'             => 'POST',
                'callback'            => [$save_controller, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Register `wp tube migrate`, `wp tube-core views:flush`/
     * `stats:rollup`/`views:partition-maintenance`/`views:seed-baseline`,
     * `wp tube-core import:enqueue`/`import:process`/`import:status`,
     * `wp tube-core watch-history:purge`, and `wp tube-core stream:resync`
     * when running under WP-CLI.
     */
    private function register_cli_commands(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        WP_CLI::add_command('tube migrate', new MigrateCommand($this->migration_runner()));

        $views_repository = new VideoViewsRepository();

        $views_command = new ViewsCommand(
            new ViewsFlusher($this->view_counter(), $views_repository, $this->video_statistics_repository()),
            new StatsRollup($views_repository, $this->video_statistics_repository(), $this->events()),
            new Retention($views_repository),
            $this->video_statistics_repository()
        );

        // Registered as individually-named commands, not one class with
        // WP-CLI's usual space-separated subcommands — see ViewsCommand's
        // own docblock for why.
        WP_CLI::add_command('tube-core views:flush', [$views_command, 'flush']);
        WP_CLI::add_command('tube-core stats:rollup', [$views_command, 'rollup']);
        WP_CLI::add_command('tube-core views:partition-maintenance', [$views_command, 'partition_maintenance']);
        WP_CLI::add_command('tube-core views:seed-baseline', [$views_command, 'seed_baseline']);

        $queue_repository = new ImportQueueRepository();

        $import_command = new ImportCommand(
            $queue_repository,
            new BatchProcessor(
                $queue_repository,
                new VideoImporter($this->video_metadata_repository()),
                $this->events()
            )
        );

        WP_CLI::add_command('tube-core import:enqueue', [$import_command, 'enqueue']);
        WP_CLI::add_command('tube-core import:process', [$import_command, 'process']);
        WP_CLI::add_command('tube-core import:status', [$import_command, 'status']);

        $watch_history_command = new WatchHistoryCommand(new GuestHistoryRetention(new WatchHistoryRepository()));

        WP_CLI::add_command('tube-core watch-history:purge', [$watch_history_command, 'purge']);

        $stream_command = new StreamCommand($this->video_metadata_repository(), $this->stream_metadata_syncer());

        WP_CLI::add_command('tube-core stream:resync', [$stream_command, 'resync']);
    }
}
