<?php
/**
 * Keeps wp_tube_search_index in sync with tube-core's video lifecycle/stats events.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Events;

use Tube_Core\Plugin as Tube_Core_Plugin;
use Tube_Search\Index\SearchIndexRepositoryInterface;
use Tube_Search\Index\VideoIndexer;

/**
 * Keeps `wp_tube_search_index` in sync with tube-core's video lifecycle/
 * stats events, per ARCHITECTURE.md §2.6's "kept in sync incrementally,
 * a single-row upsert triggered by the event system the instant a video
 * is published/updated/deleted."
 *
 * Subscribes via WordPress's native `add_action()` on tube-core's
 * documented, versioned hook-name strings (ARCHITECTURE.md §6) —
 * deliberately *not* by depending on `Tube_Core\Events\Dispatcher`/
 * `EventCatalog` as PHP types, for the same three reasons
 * `Tube_Cache\Events\CachePurgeSubscriber` documents in full (Phase 3):
 * hook-name-string subscription works whether tube-core happens to be
 * active or not, keeps tube-search's own PHPUnit suite free of a real
 * `Tube_Core\*` dependency, and depends on exactly the documented public
 * contract (the event names) rather than tube-core's internal classes.
 * `register()` is WordPress-hook-signature-coupled and verified live;
 * every handler here delegates its real decision to `VideoIndexer` or a
 * single repository call, both verified via integration tests instead
 * of unit tests, since both are themselves WordPress/tube-core-coupled.
 */
final class SearchIndexSyncSubscriber
{
    /**
     * Must match Tube_Core\Events\EventCatalog::VIDEO_PUBLISHED exactly.
     */
    private const VIDEO_PUBLISHED = 'tube_core.video.published';

    /**
     * Must match Tube_Core\Events\EventCatalog::VIDEO_UPDATED exactly.
     */
    private const VIDEO_UPDATED = 'tube_core.video.updated';

    /**
     * Must match Tube_Core\Events\EventCatalog::VIDEO_DELETED exactly.
     */
    private const VIDEO_DELETED = 'tube_core.video.deleted';

    /**
     * Must match Tube_Core\Events\EventCatalog::VIDEO_STREAM_STATUS_CHANGED exactly.
     */
    private const VIDEO_STREAM_STATUS_CHANGED = 'tube_core.video.stream_status_changed';

    /**
     * Must match Tube_Core\Events\EventCatalog::VIDEO_STATS_ROLLED_UP exactly.
     */
    private const VIDEO_STATS_ROLLED_UP = 'tube_core.video.stats_rolled_up';

    /**
     * Construct around the indexer and repository this subscriber delegates to.
     *
     * @param VideoIndexer                   $indexer    Handles the full-resync path (published/updated/deleted).
     * @param SearchIndexRepositoryInterface $repository Handles the cheap single-column paths (duration/views_total).
     */
    public function __construct(
        private readonly VideoIndexer $indexer,
        private readonly SearchIndexRepositoryInterface $repository
    ) {
    }

    /**
     * Wire this class's handlers to tube-core's hooks.
     *
     * Every dispatch from Tube_Core\Events\WordPressHookBus passes
     * exactly one argument (the event payload array), so $accepted_args
     * is always 1 here — the same contract CachePurgeSubscriber relies
     * on from the dispatching side.
     */
    public function register(): void
    {
        add_action(self::VIDEO_PUBLISHED, [$this, 'handle_video_published'], 10, 1);
        add_action(self::VIDEO_UPDATED, [$this, 'handle_video_updated'], 10, 1);
        add_action(self::VIDEO_DELETED, [$this, 'handle_video_deleted'], 10, 1);
        add_action(self::VIDEO_STREAM_STATUS_CHANGED, [$this, 'handle_video_stream_status_changed'], 10, 1);
        add_action(self::VIDEO_STATS_ROLLED_UP, [$this, 'handle_video_stats_rolled_up'], 10, 1);
    }

    /**
     * `tube_core.video.published` handler — full resync.
     *
     * @param array<string, mixed> $payload Carries `video_id` per EVENTS.md.
     */
    public function handle_video_published(array $payload): void
    {
        $this->resync($payload);
    }

    /**
     * `tube_core.video.updated` handler — full resync (taxonomy
     * assignment or publish status may have changed).
     *
     * @param array<string, mixed> $payload Carries `video_id` per EVENTS.md.
     */
    public function handle_video_updated(array $payload): void
    {
        $this->resync($payload);
    }

    /**
     * `tube_core.video.deleted` handler — direct removal.
     *
     * @param array<string, mixed> $payload Carries `video_id` per EVENTS.md.
     */
    public function handle_video_deleted(array $payload): void
    {
        $video_id = self::extract_video_id($payload);

        if (null !== $video_id) {
            $this->indexer->remove($video_id);
        }
    }

    /**
     * `tube_core.video.stream_status_changed` handler — the cheap
     * single-column path: only `duration_seconds` can change here, and
     * the payload doesn't carry it (only `video_id`/`status`), so this
     * re-reads tube-core's metadata for the current value rather than
     * running a full resync.
     *
     * @param array<string, mixed> $payload Carries `video_id`/`status` per EVENTS.md.
     */
    public function handle_video_stream_status_changed(array $payload): void
    {
        $video_id = self::extract_video_id($payload);

        if (null === $video_id) {
            return;
        }

        $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id);

        if (null !== $metadata?->duration_seconds) {
            $this->repository->update_duration($video_id, $metadata->duration_seconds);
        }
    }

    /**
     * `tube_core.video.stats_rolled_up` handler — the cheap single-
     * column path, straight from the payload (no extra query needed),
     * avoiding a full resync on every 5-minute rollup for every video.
     *
     * @param array<string, mixed> $payload Carries `video_id`/`views_total` per EVENTS.md.
     */
    public function handle_video_stats_rolled_up(array $payload): void
    {
        $video_id = self::extract_video_id($payload);

        if (null === $video_id || ! isset($payload['views_total']) || ! is_numeric($payload['views_total'])) {
            return;
        }

        $this->repository->update_views_total($video_id, (int) $payload['views_total']);
    }

    /**
     * Shared implementation behind the published/updated handlers.
     *
     * @param array<string, mixed> $payload The event payload.
     */
    private function resync(array $payload): void
    {
        $video_id = self::extract_video_id($payload);

        if (null !== $video_id) {
            $this->indexer->index($video_id);
        }
    }

    /**
     * Extract `video_id` from an event payload — a malformed payload is
     * logged and ignored rather than left to throw out of a WordPress
     * hook callback, the same fail-open-and-log principle
     * `CachePurgeSubscriber::extract_video_id()` already applies.
     *
     * @param array<string, mixed> $payload The event payload.
     */
    private static function extract_video_id(array $payload): ?int
    {
        if (! isset($payload['video_id']) || ! is_numeric($payload['video_id'])) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate production logging for a malformed event payload that is otherwise silently ignored; not leftover debug code.
            error_log('[tube-search] Event payload is missing a numeric "video_id".');

            return null;
        }

        return (int) $payload['video_id'];
    }
}
