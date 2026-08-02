<?php
/**
 * The documented catalog of every event tube-core dispatches.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Events;

/**
 * The documented catalog of every event tube-core dispatches, per
 * ARCHITECTURE.md §6.
 *
 * This is the "stable, versioned contract other plugins can rely on
 * instead of guessing hook names by convention" §6 describes — Dispatcher
 * validates every dispatch()/listen() call against this list, so a typo'd
 * event name fails fast instead of silently never firing. See EVENTS.md
 * for the full human-readable catalog (payload shapes, when each fires,
 * expected subscribers).
 *
 * Event name strings are prefixed `tube_core.` (e.g. `tube_core.video.published`
 * rather than the bare `video.published` shorthand ARCHITECTURE.md's
 * catalog table uses) so the underlying WordPress hook names can never
 * collide with an unrelated plugin's own hooks — consistent with this
 * project's prefixing discipline everywhere else (`wp_tube_*` tables,
 * `TUBE_CORE_*` constants, `Tube_Core\*` classes).
 *
 * Not every event listed here has a real dispatch call site yet: the four
 * `video.*` lifecycle events (Phase 2, see VideoLifecycleEvents) and the
 * two view-tracking events (Phase 4, see ViewRecorder/StatsRollup) are
 * wired to real triggers. The remaining three depend on triggers that
 * don't exist until Phase 5 (the import queue and the Cloudflare Stream
 * webhook) — they are still valid, listenable event names today (a
 * plugin built ahead of its trigger phase can register a listener
 * without error), they simply won't fire until their owning phase adds
 * the dispatch call site.
 */
final class EventCatalog
{
    /**
     * Fired once, when a new video post is first inserted.
     *
     * Payload: `['video_id' => int]`.
     */
    public const VIDEO_CREATED = 'tube_core.video.created';

    /**
     * Fired whenever an existing video post is saved.
     *
     * Payload: `['video_id' => int]`.
     */
    public const VIDEO_UPDATED = 'tube_core.video.updated';

    /**
     * Fired when a video transitions to the `publish` status from any
     * other status.
     *
     * Payload: `['video_id' => int]`.
     */
    public const VIDEO_PUBLISHED = 'tube_core.video.published';

    /**
     * Fired immediately before a video post is permanently deleted
     * (not on trash — trashing already fires VIDEO_UPDATED).
     *
     * Payload: `['video_id' => int]`.
     */
    public const VIDEO_DELETED = 'tube_core.video.deleted';

    /**
     * Reserved for Phase 5: fired from the Cloudflare Stream webhook
     * handler when a video's encoding status changes.
     *
     * Payload: `['video_id' => int, 'status' => string]`.
     */
    public const VIDEO_STREAM_STATUS_CHANGED = 'tube_core.video.stream_status_changed';

    /**
     * Fired when a view is recorded (Tube_Core\Views\ViewRecorder).
     * Internal only per ARCHITECTURE.md §6 — the stats rollup job reads
     * wp_tube_video_views directly rather than subscribing to this.
     *
     * Payload: `['video_id' => int]`.
     */
    public const VIDEO_VIEW_RECORDED = 'tube_core.video.view_recorded';

    /**
     * Fired once per video at the end of the `stats:rollup` WP-CLI job
     * (Tube_Core\Views\StatsRollup), for every video with a statistics
     * row — including ones with zero recent views.
     *
     * Payload: `['video_id' => int, 'views_total' => int]`.
     */
    public const VIDEO_STATS_ROLLED_UP = 'tube_core.video.stats_rolled_up';

    /**
     * Reserved for Phase 5: fired when an import_queue item finishes
     * successfully.
     *
     * Payload: `['queue_id' => int, 'video_id' => int]`.
     */
    public const IMPORT_ITEM_COMPLETED = 'tube_core.import.item_completed';

    /**
     * Reserved for Phase 5: fired when an import_queue item exhausts
     * its retry attempts.
     *
     * Payload: `['queue_id' => int, 'error_message' => string]`.
     */
    public const IMPORT_ITEM_FAILED = 'tube_core.import.item_failed';

    /**
     * Every known event name, for validating dispatch()/listen() calls against.
     *
     * @return list<non-empty-string>
     */
    public static function all(): array
    {
        return [
            self::VIDEO_CREATED,
            self::VIDEO_UPDATED,
            self::VIDEO_PUBLISHED,
            self::VIDEO_DELETED,
            self::VIDEO_STREAM_STATUS_CHANGED,
            self::VIDEO_VIEW_RECORDED,
            self::VIDEO_STATS_ROLLED_UP,
            self::IMPORT_ITEM_COMPLETED,
            self::IMPORT_ITEM_FAILED,
        ];
    }
}
