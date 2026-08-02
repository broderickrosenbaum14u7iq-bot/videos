<?php
/**
 * Records a view: buffers it and dispatches VIDEO_VIEW_RECORDED.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Views;

use Tube_Core\Events\Dispatcher;
use Tube_Core\Events\EventCatalog;

/**
 * Records a view: buffers it via ViewCounterInterface and dispatches
 * `EventCatalog::VIDEO_VIEW_RECORDED`, per ARCHITECTURE.md §6/EVENTS.md
 * (Reserved for Phase 4 — this is that trigger).
 *
 * A small class rather than inline logic on `Plugin::record_view()` —
 * `Plugin.php` is the composition root (construct-or-return-cached only,
 * per ARCHITECTURE.md §19.2); this is where the actual two-step behavior
 * ("buffer it, then announce it") lives, and where it's unit-tested, the
 * same "WordPress-coupled boundary vs. testable core" split
 * `VideoLifecycleEvents` already established in Phase 2.
 */
final class ViewRecorder
{
    /**
     * Construct around the counter a view is buffered in and the
     * dispatcher its event is announced through.
     *
     * @param ViewCounterInterface $counter    Buffers the view.
     * @param Dispatcher           $dispatcher Announces it.
     */
    public function __construct(
        private readonly ViewCounterInterface $counter,
        private readonly Dispatcher $dispatcher
    ) {
    }

    /**
     * Record one view for a video.
     *
     * @param int $video_id The video that was viewed.
     */
    public function record(int $video_id): void
    {
        $this->counter->record($video_id);

        $this->dispatcher->dispatch(EventCatalog::VIDEO_VIEW_RECORDED, ['video_id' => $video_id]);
    }
}
