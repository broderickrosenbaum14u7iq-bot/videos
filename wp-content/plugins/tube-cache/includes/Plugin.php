<?php
/**
 * Tube Cache's bootstrap.
 *
 * @package Tube_Cache
 */

declare(strict_types=1);

namespace Tube_Cache;

use Predis\Client;
use Tube_Cache\Cache\CacheInterface;
use Tube_Cache\Cache\RedisCache;
use Tube_Cache\Events\CachePurgeSubscriber;
use Tube_Cache\RateLimit\RateLimiter;

/**
 * Tube Cache's bootstrap: wires the Redis-backed cache, the rate
 * limiter, and the cache-purge event subscriber into WordPress.
 *
 * Same lazy-singleton composition-root shape as Tube_Core\Plugin — three
 * accessors, well under the 6-8-accessor reconsideration trigger
 * documented in ARCHITECTURE.md §19.2.
 */
final class Plugin
{
    /**
     * The Redis host to connect to, when TUBE_CACHE_REDIS_HOST is not
     * defined (e.g. a local environment without the Docker Compose
     * config wiring it in).
     */
    private const DEFAULT_REDIS_HOST = '127.0.0.1';

    /**
     * The Redis port to connect to, when TUBE_CACHE_REDIS_PORT is not defined.
     */
    private const DEFAULT_REDIS_PORT = 6379;

    /**
     * The Redis logical database index to SELECT, when
     * TUBE_CACHE_REDIS_DB is not defined. Redis's own multi-database
     * feature (0-15 by default) is the standard way to isolate unrelated
     * applications sharing one Redis server without needing a separate
     * process per site — every cache key in this plugin
     * (`CacheKeys::trending()`, `::most_viewed()`, etc.) is a plain,
     * unnamespaced string, so two independent WordPress installs that
     * both fall back to this same default host/port would otherwise
     * silently read and overwrite each other's cached "Trending"/"Most
     * Viewed"/"Recently Added" listings — exactly the cross-site data
     * leak found running phimtoico.org and dongtoico.org on the same
     * VPS. Each independent site sharing this Redis server must set its
     * own distinct `TUBE_CACHE_REDIS_DB` value.
     */
    private const DEFAULT_REDIS_DATABASE = 0;

    /**
     * The shared instance, lazily created by self::instance().
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Lazily created by self::cache().
     *
     * @var CacheInterface|null
     */
    private ?CacheInterface $cache = null;

    /**
     * Lazily created by self::rate_limiter().
     *
     * @var RateLimiter|null
     */
    private ?RateLimiter $rate_limiter = null;

    /**
     * Lazily created by self::cache_purge_subscriber().
     *
     * @var CachePurgeSubscriber|null
     */
    private ?CachePurgeSubscriber $cache_purge_subscriber = null;

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
        $this->cache_purge_subscriber()->register();
    }

    /**
     * The Redis-backed cache, per ARCHITECTURE.md §12 Phase 3.
     *
     * Public so tube-player, tube-search, and every future consumer
     * cache computed values behind this — the same "public accessor for
     * a cross-cutting concern" shape as Tube_Core\Plugin::events().
     */
    public function cache(): CacheInterface
    {
        if (null === $this->cache) {
            $host     = defined('TUBE_CACHE_REDIS_HOST') ? TUBE_CACHE_REDIS_HOST : self::DEFAULT_REDIS_HOST;
            $port     = defined('TUBE_CACHE_REDIS_PORT') ? TUBE_CACHE_REDIS_PORT : self::DEFAULT_REDIS_PORT;
            $database = defined('TUBE_CACHE_REDIS_DB') ? TUBE_CACHE_REDIS_DB : self::DEFAULT_REDIS_DATABASE;

            $this->cache = new RedisCache(
                new Client(
                    [
                        'host'     => $host,
                        'port'     => $port,
                        'database' => $database,
                    ]
                )
            );
        }

        return $this->cache;
    }

    /**
     * The rate-limit helper, per ARCHITECTURE.md §12 Phase 3.
     *
     * Public for the same reason self::cache() is — Phase 4's
     * view-recording endpoint and later API-key rate limiting (§17) are
     * both future consumers of this accessor.
     */
    public function rate_limiter(): RateLimiter
    {
        if (null === $this->rate_limiter) {
            $this->rate_limiter = new RateLimiter($this->cache());
        }

        return $this->rate_limiter;
    }

    /**
     * The cache-purge event subscriber, wired to tube-core's video
     * lifecycle hooks in self::boot().
     */
    private function cache_purge_subscriber(): CachePurgeSubscriber
    {
        if (null === $this->cache_purge_subscriber) {
            $this->cache_purge_subscriber = new CachePurgeSubscriber($this->cache());
        }

        return $this->cache_purge_subscriber;
    }
}
