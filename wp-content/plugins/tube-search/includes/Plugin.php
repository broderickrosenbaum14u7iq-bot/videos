<?php
/**
 * Tube Search's bootstrap.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search;

use Tube_Core\Plugin as Tube_Core_Plugin;
use Tube_Search\Cache\SearchCacheInterface;
use Tube_Search\Cache\TubeCacheAdapter;
use Tube_Search\CLI\IndexCommand;
use Tube_Search\Discovery\ArchiveVideosQuery;
use Tube_Search\Discovery\PopularVideosQuery;
use Tube_Search\Discovery\RecentlyAddedQuery;
use Tube_Search\Discovery\RelatedVideosFinder;
use Tube_Search\Discovery\TubeCorePopularityRepository;
use Tube_Search\Events\SearchIndexSyncSubscriber;
use Tube_Search\Index\SearchIndexRepository;
use Tube_Search\Index\VideoIndexer;
use Tube_Search\Search\SearchQuery;
use Tube_Search\Search\SearchRouting;
use Tube_Search\SchemaMigrations\Migration001CreateSearchIndexTable;
use Tube_Search\SchemaMigrations\Migration002AddNormalizedSearchText;
use WP_CLI;

/**
 * Tube Search's bootstrap: registers its migration against tube-core's
 * shared runner, wires the index-sync event subscriber, and provides
 * lazy-singleton accessors for the discovery/query classes — the same
 * composition-root shape `Tube_Core\Plugin`/`Tube_Cache\Plugin`/
 * `Tube_Player\Plugin` already use.
 *
 * 9 lazy accessors total (6 public, 3 private) — past ARCHITECTURE.md
 * §19.2's 6–8-accessor reconsideration trigger as of Phase 8's
 * `archive_videos_query()` addition. Reconsidered, not just flagged: per
 * §19.2's own actual test, a service container is warranted only once
 * "a single plugin's bootstrap class... starts containing real logic
 * beyond construction/wiring" — every accessor here, including the new
 * one, is still exactly `new` + cache, nothing more, so that condition
 * still isn't met and a container remains the wrong call. See `PHASE-8.md`.
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
     * Lazily created by self::search_index_repository().
     *
     * @var SearchIndexRepository|null
     */
    private ?SearchIndexRepository $search_index_repository = null;

    /**
     * Lazily created by self::cache().
     *
     * @var SearchCacheInterface|null
     */
    private ?SearchCacheInterface $cache = null;

    /**
     * Lazily created by self::indexer().
     *
     * @var VideoIndexer|null
     */
    private ?VideoIndexer $indexer = null;

    /**
     * Lazily created by self::sync_subscriber().
     *
     * @var SearchIndexSyncSubscriber|null
     */
    private ?SearchIndexSyncSubscriber $sync_subscriber = null;

    /**
     * Lazily created by self::related_videos_finder().
     *
     * @var RelatedVideosFinder|null
     */
    private ?RelatedVideosFinder $related_videos_finder = null;

    /**
     * Lazily created by self::popular_videos_query().
     *
     * @var PopularVideosQuery|null
     */
    private ?PopularVideosQuery $popular_videos_query = null;

    /**
     * Lazily created by self::recently_added_query().
     *
     * @var RecentlyAddedQuery|null
     */
    private ?RecentlyAddedQuery $recently_added_query = null;

    /**
     * Lazily created by self::search_query().
     *
     * @var SearchQuery|null
     */
    private ?SearchQuery $search_query = null;

    /**
     * Lazily created by self::archive_videos_query().
     *
     * @var ArchiveVideosQuery|null
     */
    private ?ArchiveVideosQuery $archive_videos_query = null;

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
        Tube_Core_Plugin::instance()->migration_runner()->register_source(
            'tube-search',
            [
                Migration001CreateSearchIndexTable::class,
                Migration002AddNormalizedSearchText::class,
            ]
        );

        $this->sync_subscriber()->register();

        $search_routing = new SearchRouting();

        add_action('init', [$search_routing, 'add_rewrite_rules']);
        add_filter('query_vars', [$search_routing, 'register_query_var']);
        add_filter('template_include', [$search_routing, 'route_template']);

        $this->register_cli_commands();
    }

    /**
     * Plugin activation: register the `/search/{query}/` rewrite rule
     * synchronously (so it exists before the flush below), then flush
     * rewrite rules — the same pattern `Tube_Core\Plugin::activate()` uses.
     */
    public static function activate(): void
    {
        (new SearchRouting())->add_rewrite_rules();

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation.
     */
    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    /**
     * The search-index repository, per ARCHITECTURE.md §12 Phase 7.
     *
     * Public so a future consumer with a genuine need for raw index
     * access (tube-admin's Phase 10 dashboard, e.g. "index staleness")
     * can reach it without a new accessor — every actual discovery
     * feature goes through the narrower query classes below instead.
     */
    public function search_index_repository(): SearchIndexRepository
    {
        if (null === $this->search_index_repository) {
            $this->search_index_repository = new SearchIndexRepository();
        }

        return $this->search_index_repository;
    }

    /**
     * The cache tube-search's discovery queries read/write through.
     */
    private function cache(): SearchCacheInterface
    {
        if (null === $this->cache) {
            $this->cache = new TubeCacheAdapter();
        }

        return $this->cache;
    }

    /**
     * The video indexer, shared by the event subscriber and `index:rebuild`.
     */
    private function indexer(): VideoIndexer
    {
        if (null === $this->indexer) {
            $this->indexer = new VideoIndexer($this->search_index_repository());
        }

        return $this->indexer;
    }

    /**
     * The index-sync event subscriber, wired to tube-core's hooks in self::boot().
     */
    private function sync_subscriber(): SearchIndexSyncSubscriber
    {
        if (null === $this->sync_subscriber) {
            $this->sync_subscriber = new SearchIndexSyncSubscriber(
                $this->indexer(),
                $this->search_index_repository()
            );
        }

        return $this->sync_subscriber;
    }

    /**
     * The related-videos finder, per ARCHITECTURE.md §12 Phase 7.
     *
     * Public: `includes/template-tags.php`'s `tube_search_related_videos()` is a thin wrapper around this.
     */
    public function related_videos_finder(): RelatedVideosFinder
    {
        if (null === $this->related_videos_finder) {
            $this->related_videos_finder = new RelatedVideosFinder($this->search_index_repository(), $this->cache());
        }

        return $this->related_videos_finder;
    }

    /**
     * The "Trending"/"Most Viewed" query, per ARCHITECTURE.md §12 Phase 7.
     *
     * Public: `includes/template-tags.php`'s `tube_search_trending()`/`tube_search_most_viewed()` wrap this.
     */
    public function popular_videos_query(): PopularVideosQuery
    {
        if (null === $this->popular_videos_query) {
            $this->popular_videos_query = new PopularVideosQuery(
                new TubeCorePopularityRepository(),
                $this->search_index_repository(),
                $this->cache()
            );
        }

        return $this->popular_videos_query;
    }

    /**
     * The "Recently Added" query, per ARCHITECTURE.md §12 Phase 7.
     *
     * Public: `includes/template-tags.php`'s `tube_search_recently_added()` is a thin wrapper around this.
     */
    public function recently_added_query(): RecentlyAddedQuery
    {
        if (null === $this->recently_added_query) {
            $this->recently_added_query = new RecentlyAddedQuery($this->search_index_repository(), $this->cache());
        }

        return $this->recently_added_query;
    }

    /**
     * The full-text search query, per ARCHITECTURE.md §5's `tube_search_query()`.
     *
     * Public: `includes/template-tags.php`'s `tube_search_query()` is a thin wrapper around this.
     */
    public function search_query(): SearchQuery
    {
        if (null === $this->search_query) {
            $this->search_query = new SearchQuery($this->search_index_repository(), $this->cache());
        }

        return $this->search_query;
    }

    /**
     * The category/tag/actor/studio archive-listing query, per ARCHITECTURE.md §15.1/§12 Phase 8.
     *
     * Public: `includes/template-tags.php`'s `tube_search_by_category()`/
     * `tube_search_by_tag()`/`tube_search_by_actor()`/`tube_search_by_studio()` all wrap this.
     */
    public function archive_videos_query(): ArchiveVideosQuery
    {
        if (null === $this->archive_videos_query) {
            $this->archive_videos_query = new ArchiveVideosQuery($this->search_index_repository(), $this->cache());
        }

        return $this->archive_videos_query;
    }

    /**
     * Register `wp tube-search index:rebuild` when running under WP-CLI.
     */
    private function register_cli_commands(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        $index_command = new IndexCommand($this->indexer(), $this->search_index_repository());

        WP_CLI::add_command('tube-search index:rebuild', [$index_command, 'rebuild']);
    }
}
