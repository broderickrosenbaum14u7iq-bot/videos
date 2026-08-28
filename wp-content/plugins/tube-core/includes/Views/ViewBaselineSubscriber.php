<?php
/**
 * Seeds a video's views_total baseline the moment it's published.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Views;

use Tube_Core\Events\EventCatalog;
use Tube_Core\Views\Repositories\VideoStatisticsRepositoryInterface;

/**
 * Seeds a video's `wp_tube_video_statistics.views_total` baseline the
 * moment it's published, per the project owner's explicit decision
 * (2026-08-25): every video's real, canonical `views_total` starts at
 * `self::BASELINE_VIEWS`, not 0 — a deliberate business decision applied
 * directly to the real counter (not a separate display/popularity
 * value), incremented from there by genuine recorded views exactly like
 * any other video.
 *
 * Listens for `EventCatalog::VIDEO_PUBLISHED` — the same event
 * `Tube_Search\Events\SearchIndexSyncSubscriber`/
 * `Tube_Cache\Events\CachePurgeSubscriber` already react to, fired
 * exactly once per video's first transition into `publish` status
 * (`Tube_Core\Events\VideoLifecycleEvents::dispatch_status_transition()`).
 * `VideoStatisticsRepositoryInterface::ensure_baseline()` is an atomic
 * "insert if missing" — a video published before this subscriber existed
 * (or before any migration/backfill ran) is caught by
 * `wp tube-core views:seed-baseline` instead (see `Tube_Core\CLI\ViewsCommand`);
 * this subscriber only ever needs to handle the "this video has no
 * statistics row yet" case, since a real view can only be recorded
 * against an already-published video, and by then this handler has
 * already run for it.
 *
 * A dedicated small class, the same shape `Tube_Search\Events\SearchIndexSyncSubscriber`/
 * `Tube_Cache\Events\CachePurgeSubscriber` already use for "react to one
 * of tube-core's own events" — the first case of tube-core reacting to
 * its own event, since seeding this baseline is tube-core's own table's
 * concern, not another plugin's.
 */
final class ViewBaselineSubscriber
{
    /**
     * The `views_total` every video's statistics row starts at.
     */
    public const BASELINE_VIEWS = 1000;

    /**
     * Construct around the repository this writes to.
     *
     * @param VideoStatisticsRepositoryInterface $statistics_repository Where the baseline is seeded.
     */
    public function __construct(private readonly VideoStatisticsRepositoryInterface $statistics_repository)
    {
    }

    /**
     * Wire this class's handler to `VIDEO_PUBLISHED`. Called once from `Tube_Core\Plugin::boot()`.
     */
    public function register(): void
    {
        add_action(EventCatalog::VIDEO_PUBLISHED, [$this, 'handle_video_published'], 10, 1);
    }

    /**
     * `tube_core.video.published` handler: seed the baseline.
     *
     * @param array<string, mixed> $payload Carries `video_id` per EVENTS.md.
     */
    public function handle_video_published(array $payload): void
    {
        $video_id = $payload['video_id'] ?? null;

        if (! is_int($video_id)) {
            return;
        }

        $this->statistics_repository->ensure_baseline($video_id, self::BASELINE_VIEWS);
    }
}
