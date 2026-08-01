<?php
/**
 * Unit tests for VideoLifecycleEvents.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use Tube_Core\Events\Dispatcher;
use Tube_Core\Events\EventCatalog;
use Tube_Core\Events\VideoLifecycleEvents;
use Tube_Core\Tests\Unit\Events\Fixtures\RecordingHookBus;

/**
 * Exercises VideoLifecycleEvents' dispatch_*() decision logic directly
 * (bypassing the WP_Post-typed handle_*() adapters, which need
 * WordPress loaded) against a real Dispatcher wired to a fake
 * HookBusInterface — no WordPress dependency.
 */
final class VideoLifecycleEventsTest extends TestCase
{
    /**
     * The fake hook bus the dispatcher under test is wired to.
     *
     * @var RecordingHookBus
     */
    private RecordingHookBus $hook_bus;

    /**
     * The class under test.
     *
     * @var VideoLifecycleEvents
     */
    private VideoLifecycleEvents $lifecycle_events;

    /**
     * Build a fresh VideoLifecycleEvents, backed by a real Dispatcher and a fake hook bus, for each test.
     */
    protected function setUp(): void
    {
        $this->hook_bus         = new RecordingHookBus();
        $this->lifecycle_events = new VideoLifecycleEvents(new Dispatcher($this->hook_bus));
    }

    /**
     * A brand-new post (update = false) dispatches VIDEO_CREATED.
     */
    public function test_dispatch_save_with_update_false_dispatches_video_created(): void
    {
        $this->lifecycle_events->dispatch_save(101, false);

        $expected = [
            [
                'hook'    => EventCatalog::VIDEO_CREATED,
                'payload' => ['video_id' => 101],
            ],
        ];

        self::assertSame($expected, $this->hook_bus->dispatched);
    }

    /**
     * Saving an existing post (update = true) dispatches VIDEO_UPDATED, not VIDEO_CREATED.
     */
    public function test_dispatch_save_with_update_true_dispatches_video_updated(): void
    {
        $this->lifecycle_events->dispatch_save(101, true);

        $expected = [
            [
                'hook'    => EventCatalog::VIDEO_UPDATED,
                'payload' => ['video_id' => 101],
            ],
        ];

        self::assertSame($expected, $this->hook_bus->dispatched);
    }

    /**
     * A transition into publish from any other status dispatches VIDEO_PUBLISHED.
     */
    public function test_dispatch_status_transition_from_draft_to_publish_dispatches_video_published(): void
    {
        $this->lifecycle_events->dispatch_status_transition(101, 'publish', 'draft');

        $expected = [
            [
                'hook'    => EventCatalog::VIDEO_PUBLISHED,
                'payload' => ['video_id' => 101],
            ],
        ];

        self::assertSame($expected, $this->hook_bus->dispatched);
    }

    /**
     * A transition into publish from pending review also counts.
     */
    public function test_dispatch_status_transition_from_pending_to_publish_dispatches_video_published(): void
    {
        $this->lifecycle_events->dispatch_status_transition(101, 'publish', 'pending');

        self::assertCount(1, $this->hook_bus->dispatched);
        self::assertSame(EventCatalog::VIDEO_PUBLISHED, $this->hook_bus->dispatched[0]['hook']);
    }

    /**
     * Staying published (publish to publish, e.g. a routine re-save)
     * does not dispatch VIDEO_PUBLISHED again.
     */
    public function test_dispatch_status_transition_from_publish_to_publish_dispatches_nothing(): void
    {
        $this->lifecycle_events->dispatch_status_transition(101, 'publish', 'publish');

        self::assertSame([], $this->hook_bus->dispatched);
    }

    /**
     * Leaving publish (e.g. publish to draft) does not dispatch VIDEO_PUBLISHED.
     */
    public function test_dispatch_status_transition_away_from_publish_dispatches_nothing(): void
    {
        $this->lifecycle_events->dispatch_status_transition(101, 'draft', 'publish');

        self::assertSame([], $this->hook_bus->dispatched);
    }

    /**
     * A transition between two non-publish statuses dispatches nothing.
     */
    public function test_dispatch_status_transition_between_non_publish_statuses_dispatches_nothing(): void
    {
        $this->lifecycle_events->dispatch_status_transition(101, 'pending', 'draft');

        self::assertSame([], $this->hook_bus->dispatched);
    }

    /**
     * Dispatches VIDEO_DELETED with the video's ID.
     */
    public function test_dispatch_delete_dispatches_video_deleted(): void
    {
        $this->lifecycle_events->dispatch_delete(101);

        $expected = [
            [
                'hook'    => EventCatalog::VIDEO_DELETED,
                'payload' => ['video_id' => 101],
            ],
        ];

        self::assertSame($expected, $this->hook_bus->dispatched);
    }
}
