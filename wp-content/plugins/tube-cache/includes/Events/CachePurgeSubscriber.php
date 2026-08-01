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
 * Purges cached video-detail entries in reaction to tube-core's video
 * lifecycle events, per ARCHITECTURE.md §12 Phase 3 and §16's "Redis
 * object cache: purge on video.published/updated/deleted" row.
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
    }

    /**
     * `tube_core.video.published` handler.
     *
     * @param array<string, mixed> $payload Carries `video_id` per EVENTS.md.
     */
    public function handle_video_published(array $payload): void
    {
        $this->purge_from_payload($payload);
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
     * `tube_core.video.deleted` handler.
     *
     * @param array<string, mixed> $payload Carries `video_id` per EVENTS.md.
     */
    public function handle_video_deleted(array $payload): void
    {
        $this->purge_from_payload($payload);
    }

    /**
     * Purge the cached detail entry for one video.
     *
     * Only the video's own detail key is purged in this phase. Purging
     * the listing keys ARCHITECTURE.md §16 also describes (per
     * category/tag/actor/studio the video belongs to) needs those
     * listings' own query/repository layer to exist first (tube-search,
     * Phase 7; the actor/studio repositories, deferred per
     * ARCHITECTURE_FREEZE.md's Flexible Decisions #2) — building that
     * purge logic now, against tables/queries that don't exist yet, would
     * be exactly the kind of speculative work Phase 2's precedent
     * (deferring the five trigger-less events) already established this
     * project does not do. Extending this method is the natural place to
     * add that purging once its real trigger phase builds it.
     *
     * @param int $video_id The video whose cached detail entry should be purged.
     */
    public function purge_video(int $video_id): void
    {
        $this->cache->delete(CacheKeys::video_detail($video_id));
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
