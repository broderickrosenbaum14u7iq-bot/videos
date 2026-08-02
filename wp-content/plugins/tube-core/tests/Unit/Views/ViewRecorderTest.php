<?php
/**
 * Unit tests for ViewRecorder.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Views;

use PHPUnit\Framework\TestCase;
use Tube_Core\Events\Dispatcher;
use Tube_Core\Events\EventCatalog;
use Tube_Core\Tests\Unit\Events\Fixtures\RecordingHookBus;
use Tube_Core\Tests\Unit\Views\Fixtures\InMemoryViewCounter;
use Tube_Core\Views\ViewRecorder;

/**
 * Exercises ViewRecorder against a fake counter and a real Dispatcher
 * wired to a fake hook bus — no Redis, no WordPress.
 */
final class ViewRecorderTest extends TestCase
{
    /**
     * The fake counter the recorder under test buffers into.
     *
     * @var InMemoryViewCounter
     */
    private InMemoryViewCounter $counter;

    /**
     * The fake hook bus the dispatcher under test is wired to.
     *
     * @var RecordingHookBus
     */
    private RecordingHookBus $hook_bus;

    /**
     * The recorder under test.
     *
     * @var ViewRecorder
     */
    private ViewRecorder $recorder;

    /**
     * Build a fresh recorder, counter, and dispatcher for each test.
     */
    protected function setUp(): void
    {
        $this->counter  = new InMemoryViewCounter();
        $this->hook_bus = new RecordingHookBus();
        $this->recorder = new ViewRecorder($this->counter, new Dispatcher($this->hook_bus));
    }

    /**
     * Recording a view buffers it in the counter.
     */
    public function test_record_buffers_the_view(): void
    {
        $this->recorder->record(42);

        self::assertSame([42 => 1], $this->counter->flush());
    }

    /**
     * Recording a view dispatches VIDEO_VIEW_RECORDED with the video ID.
     */
    public function test_record_dispatches_video_view_recorded(): void
    {
        $this->recorder->record(42);

        self::assertSame(
            [
                [
                    'hook'    => EventCatalog::VIDEO_VIEW_RECORDED,
                    'payload' => ['video_id' => 42],
                ],
            ],
            $this->hook_bus->dispatched
        );
    }

    /**
     * Recording the same video twice buffers two views and dispatches twice.
     */
    public function test_recording_the_same_video_twice_buffers_and_dispatches_twice(): void
    {
        $this->recorder->record(42);
        $this->recorder->record(42);

        self::assertSame([42 => 2], $this->counter->flush());
        self::assertCount(2, $this->hook_bus->dispatched);
    }
}
