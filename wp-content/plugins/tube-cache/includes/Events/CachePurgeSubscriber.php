<?php
/**
 * Purges cached video-detail entries in reaction to tube-core's video lifecycle events.
 *
 * @package Tube_Cache
 */

declare(strict_types=1);

namespace Tube_Cache\Events;

use InvalidArgumentException;
use Tube_Cache\Cache\CacheInterface;
use Tube_Cache\Cache\CacheKeys;

/**
 * Purges cached video-detail and discovery-listing entries in reaction
 * to tube-core's video lifecycle/stats events, per ARCHITECTURE.md §12
 * Phase 3's "Redis object cache: purge on video.published/updated/
 * deleted" row and §16.1's exact per-event purge table — extended in
 * Phase 7 to cover tube-search's discovery caches (`related_videos`,
 * `trending`, `most_viewed`, `recently_added`) using the same
 * `CacheKeys` builders tube-search's own query classes write under, so
 * the key a writer uses and the key this subscriber deletes are
 * guaranteed to match.
 *
 * Subscribes via WordPress's native add_action() on tube-core's
 * documented, versioned hook-name strings (ARCHITECTURE.md §6) —
 * deliberately *not* by depending on Tube_Core\Events\Dispatcher or
 * Tube_Core\Events\EventCatalog as PHP types. Three reasons, all real:
 *
 * 1. tube-cache is the one plugin that does not declare
 *    `Requires Plugins: tube-core` (see tube-cache.php's own header note,
 *    ARCHITECTURE.md §4) — it is an independent utility other plugins
 *    consume, not a tube-core dependent. Subscribing by hook-name string
 *    means this class works correctly whether tube-core is active or
 *    not: if inactive, the hook simply never fires, with no defensive
 *    class_exists() check needed anywhere.
 * 2. Every tube-* plugin is independently composer-installable
 *    (DEVELOPMENT_RULES.md §2) — tube-cache's own composer.json has no
 *    dependency on tube-core's package, so its own PHPUnit suite cannot
 *    autoload Tube_Core\* classes. A hook-name-string subscription keeps
 *    purge_video() (the actual decision logic) unit-testable with zero
 *    WordPress and zero Tube_Core dependency, the same fake-based pattern
 *    already used throughout this project.
 * 3. The event names themselves — not tube-core's PHP classes — are the
 *    documented public contract (ARCHITECTURE.md §6, EVENTS.md); a
 *    string-based subscription depends on exactly that contract and
 *    nothing more, which is the WordPress-idiomatic way two plugins
 *    integrate through action hooks.
 *
 * `register()` is the WordPress-hook-registration adapter (only usable
 * once WordPress is loaded, mirroring VideoLifecycleEvents's split from
 * tube-core Phase 2) and — like VideoLifecycleEvents::register() — has
 * no automated test of its own; it is verified live. `purge_video()` is
 * the pure decision logic and is fully unit-tested.
 */
final class CachePurgeSubscriber
{
    /**
     * Must match Tube_Core\Events\EventCatalog::VIDEO_PUBLISHED exactly —
     * see this class's docblock for why tube-cache references the
     * literal string instead of that constant.
     */
    private const VIDEO_PUBLISHED = 'tube_core.video.published';

    /**
     * Must match Tube_Core\Events\EventCatalog::VIDEO_UPDATED exactly —
     * see this class's docblock for why tube-cache references the
     * literal string instead of that constant.
     */
    private const VIDEO_UPDATED = 'tube_core.video.updated';

    /**
     * Must match Tube_Core\Events\EventCatalog::VIDEO_DELETED exactly —
     * see this class's docblock for why tube-cache references the
     * literal string instead of that constant.
     */
    private const VIDEO_DELETED = 'tube_core.video.deleted';

    /**
     * Must match Tube_Core\Events\EventCatalog::VIDEO_STATS_ROLLED_UP
     * exactly — see this class's docblock for why tube-cache references
     * the literal string instead of that constant.
     */
    private const VIDEO_STATS_ROLLED_UP = 'tube_core.video.stats_rolled_up';

    /**
     * Must match Tube_Core\Events\EventCatalog::VIDEO_STREAM_STATUS_CHANGED
     * exactly — see this class's docblock for why tube-cache references
     * the literal string instead of that constant.
     */
    private const VIDEO_STREAM_STATUS_CHANGED = 'tube_core.video.stream_status_changed';

    /**
     * Construct around the cache entries this subscriber purges.
     *
     * @param CacheInterface $cache The cache to purge entries from.
     */
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    /**
     * Wire this class's handlers to tube-core's video lifecycle hooks.
     *
     * Every dispatch from Tube_Core\Events\WordPressHookBus passes
     * exactly one argument (the event payload array), so $accepted_args
     * is always 1 here — the same contract VideoLifecycleEvents relies on
     * from the dispatching side.
     */
    public function register(): void
    {
        add_action(self::VIDEO_PUBLISHED, [$this, 'handle_video_published'], 10, 1);
        add_action(self::VIDEO_UPDATED, [$this, 'handle_video_updated'], 10, 1);
        add_action(self::VIDEO_DELETED, [$this, 'handle_video_deleted'], 10, 1);
        add_action(self::VIDEO_STATS_ROLLED_UP, [$this, 'handle_video_stats_rolled_up'], 10, 1);
        add_action(self::VIDEO_STREAM_STATUS_CHANGED, [$this, 'handle_video_stream_status_changed'], 10, 1);
    }

    /**
     * `tube_core.video.published` handler. A newly-published video can
     * change the "Recently Added" listing, in addition to its own detail/
     * related-videos keys (self::purge_video()).
     *
     * @param array<string, mixed> $payload Carries `video_id` per EVENTS.md.
     */
    public function handle_video_published(array $payload): void
    {
        $this->purge_from_payload($payload);
        $this->cache->delete(CacheKeys::recently_added());
    }

    /**
     * `tube_core.video.updated` handler.
     *
     * @param array<string, mixed> $payload Carries `video_id` per EVENTS.md.
     */
    public function handle_video_updated(array $payload): void
    {
        $this->purge_from_payload($payload);
    }

    /**
     * `tube_core.video.deleted` handler. A deleted video must stop
     * appearing in the site-wide "Trending"/"Most Viewed" listings
     * immediately, not just after their own TTL/next stats rollup — in
     * addition to its own detail/related-videos keys (self::purge_video()).
     *
     * @param array<string, mixed> $payload Carries `video_id` per EVENTS.md.
     */
    public function handle_video_deleted(array $payload): void
    {
        $this->purge_from_payload($payload);
        $this->cache->delete(CacheKeys::trending());
        $this->cache->delete(CacheKeys::most_viewed());
    }

    /**
     * `tube_core.video.stats_rolled_up` handler — fired once per video,
     * every rollup cycle (`Tube_Core\Views\StatsRollup`, every 5 minutes).
     * Per ARCHITECTURE.md §16.1's exact row for this event: purge
     * "Trending"/"Most Viewed" listing keys **only**, never an individual
     * video's own cache entry. Deliberately ignores `$payload` — these
     * two keys are purged regardless of which video the rollup is
     * currently reporting on. Purging the same two fixed keys redundantly
     * once per video in a rollup cycle is a deliberately accepted,
     * harmless cost (an idempotent Redis `DEL` on an already-purged key),
     * not a bug — see PHASE-7.md for why building a debounce mechanism
     * for this would be exactly the unjustified complexity this
     * project's "avoid enterprise patterns" instruction rules out.
     *
     * @param array<string, mixed> $payload Carries `video_id`/`views_total` per EVENTS.md — unused here (see above).
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, Squiz.Commenting.FunctionComment.Missing -- $payload is unused deliberately (see docblock above); this ignore comment sits between the docblock and signature only because PHPCS's own docblock-adjacency check requires it here.
    public function handle_video_stats_rolled_up(array $payload): void
    {
        $this->cache->delete(CacheKeys::trending());
        $this->cache->delete(CacheKeys::most_viewed());
    }

    /**
     * `tube_core.video.stream_status_changed` handler. Per ARCHITECTURE.md
     * §16.1's exact row for this event ("ready" transition): purge the
     * video's own detail key only — not related-videos, not any listing
     * key, since a Stream encoding-status change affects only how this
     * one video's own detail page renders (e.g. a "still processing"
     * placeholder becoming real playback markup), not its relationship to
     * other videos or its presence in a listing. Found missing during
     * Phase 11's cache-behavior audit: `StreamStatusUpdater` has dispatched
     * this event since Phase 5, but no subscriber ever purged on it,
     * meaning a cached "not ready yet" detail response could persist for
     * up to its full TTL after the video actually became playable.
     *
     * `status` is unused here — any status change invalidates the cached
     * detail response, not just a transition to "ready".
     *
     * @param array<string, mixed> $payload Carries `video_id`/`status` per EVENTS.md.
     */
    public function handle_video_stream_status_changed(array $payload): void
    {
        try {
            $video_id = self::extract_video_id($payload);
        } catch (InvalidArgumentException $exception) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate production logging for a malformed event payload that is otherwise silently ignored; not leftover debug code.
            error_log('[tube-cache] ' . $exception->getMessage());

            return;
        }

        $this->cache->delete(CacheKeys::video_detail($video_id));
    }

    /**
     * Purge the cached entries tied directly to one video: its own
     * detail lookup and its own related-videos list.
     *
     * Does **not** attempt to purge every *other* video's related-videos
     * list that might now include or exclude this one (e.g. a sibling
     * sharing this video's category) — that would be an unbounded fan-out
     * purge at this project's real scale, the same class of tradeoff
     * ARCHITECTURE.md §16.2 already accepts for deep archive pages.
     * `related_videos()` cache entries carry a short TTL specifically as
     * the backstop for that cross-video staleness (see
     * `Tube_Search\Discovery\RelatedVideosFinder`).
     *
     * Taxonomy/actor/studio archive-listing keys ARCHITECTURE.md §16 also
     * describes are still not purged here — that's page-listing/archive
     * caching, out of Phase 7's scope (§12 Phase 7 lists tube-search's
     * discovery features by name; general archive listing pages are a
     * theme-integration concern, Phase 8+) — not because the mechanism is
     * hard to add later.
     *
     * @param int $video_id The video whose cached entries should be purged.
     */
    public function purge_video(int $video_id): void
    {
        $this->cache->delete(CacheKeys::video_detail($video_id));
        $this->cache->delete(CacheKeys::related_videos($video_id));
    }

    /**
     * Shared implementation behind all three handle_video_*() adapters:
     * extract `video_id` from the payload and purge it.
     *
     * A malformed payload (missing/non-numeric `video_id`) is logged and
     * ignored rather than left to throw out of a WordPress hook callback
     * — the same fail-open-and-log principle RedisCache already applies
     * to Redis connection failures, applied here to the other realistic
     * failure mode this class has: purging must never be able to break
     * the tube-core video save/delete it is reacting to.
     *
     * @param array<string, mixed> $payload The event payload.
     */
    private function purge_from_payload(array $payload): void
    {
        try {
            $video_id = self::extract_video_id($payload);
        } catch (InvalidArgumentException $exception) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate production logging for a malformed event payload that is otherwise silently ignored; not leftover debug code.
            error_log('[tube-cache] ' . $exception->getMessage());

            return;
        }

        $this->purge_video($video_id);
    }

    /**
     * Extract `video_id` from an event payload.
     *
     * The payload comes from another plugin's dispatch — EVENTS.md
     * documents `video_id` as an int, but nothing in the event system
     * enforces that at the type level (the payload is `array<string,
     * mixed>` all the way through Dispatcher/HookBusInterface), so this
     * validates rather than blindly casting a `mixed` value, the same
     * "don't trust input across a boundary" standard this codebase
     * already applies to REST payloads.
     *
     * @param array<string, mixed> $payload The event payload.
     *
     * @throws InvalidArgumentException If `video_id` is missing or not numeric.
     */
    private static function extract_video_id(array $payload): int
    {
        if (! isset($payload['video_id']) || ! is_numeric($payload['video_id'])) {
            throw new InvalidArgumentException('Event payload is missing a numeric "video_id".');
        }

        return (int) $payload['video_id'];
    }
}
