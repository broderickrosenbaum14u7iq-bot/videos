<?php
/**
 * Unit tests for StreamStatusUpdater.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Stream;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tube_Core\Events\Dispatcher;
use Tube_Core\Events\EventCatalog;
use Tube_Core\Stream\StreamStatusUpdater;
use Tube_Core\Tests\Unit\Events\Fixtures\RecordingHookBus;
use Tube_Core\Tests\Unit\Video\Fixtures\InMemoryVideoMetadataRepository;
use Tube_Core\Video\CfStreamStatus;

/**
 * Exercises StreamStatusUpdater against a fake metadata repository and a
 * real Dispatcher wired to a fake hook bus — no database, no WordPress.
 *
 * Deliberately never exercises `CfStreamStatus::Ready`: that status
 * triggers `maybe_publish()`, which calls `get_post()`/`wp_update_post()`
 * directly and therefore cannot run without WordPress loaded — verified
 * live and via integration tests instead (see PHASE-5.md). Every other
 * status (`Pending`, `Processing`, `Error`) exercises the exact same
 * compare-and-update decision logic this class's docblock documents,
 * without touching that one WordPress-coupled branch.
 */
final class StreamStatusUpdaterTest extends TestCase
{
    /**
     * The fake metadata repository the updater under test reads/writes.
     *
     * @var InMemoryVideoMetadataRepository
     */
    private InMemoryVideoMetadataRepository $metadata_repository;

    /**
     * The fake hook bus the dispatcher under test is wired to.
     *
     * @var RecordingHookBus
     */
    private RecordingHookBus $hook_bus;

    /**
     * The updater under test.
     *
     * @var StreamStatusUpdater
     */
    private StreamStatusUpdater $updater;

    /**
     * Build a fresh updater and fakes for each test.
     */
    protected function setUp(): void
    {
        $this->metadata_repository = new InMemoryVideoMetadataRepository();
        $this->hook_bus            = new RecordingHookBus();
        $this->updater             = new StreamStatusUpdater(
            $this->metadata_repository,
            new Dispatcher($this->hook_bus)
        );
    }

    /**
     * An unknown Cloudflare Stream UID throws.
     */
    public function test_unknown_uid_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->updater->handle('unknown-uid', CfStreamStatus::Processing, null);
    }

    /**
     * A genuinely new status is written and announced.
     */
    public function test_a_real_status_change_is_written_and_announced(): void
    {
        $this->metadata_repository->seed('uid-1', 42, CfStreamStatus::Pending);

        $this->updater->handle('uid-1', CfStreamStatus::Processing, null);

        self::assertSame(CfStreamStatus::Processing, $this->metadata_repository->status_for(42));
        self::assertSame(
            [
                [
                    'hook'    => EventCatalog::VIDEO_STREAM_STATUS_CHANGED,
                    'payload' => [
                        'video_id' => 42,
                        'status'   => 'processing',
                    ],
                ],
            ],
            $this->hook_bus->dispatched
        );
    }

    /**
     * A redelivered webhook reporting the same status, with no new
     * duration, changes nothing and announces nothing.
     */
    public function test_a_duplicate_status_report_is_a_safe_no_op(): void
    {
        $this->metadata_repository->seed('uid-1', 42, CfStreamStatus::Processing);

        $this->updater->handle('uid-1', CfStreamStatus::Processing, null);

        self::assertSame([], $this->metadata_repository->update_status_calls);
        self::assertSame([], $this->hook_bus->dispatched);
    }

    /**
     * The same status with a newly-known duration is still written, but not announced.
     */
    public function test_same_status_with_new_duration_is_written_but_not_announced(): void
    {
        $this->metadata_repository->seed('uid-1', 42, CfStreamStatus::Processing);

        $this->updater->handle('uid-1', CfStreamStatus::Processing, 120);

        self::assertSame(
            [
                [
                    'video_id'         => 42,
                    'status'           => CfStreamStatus::Processing,
                    'duration_seconds' => 120,
                ],
            ],
            $this->metadata_repository->update_status_calls
        );
        self::assertSame([], $this->hook_bus->dispatched);
    }

    /**
     * An error status is written and announced like any other real change.
     */
    public function test_error_status_is_written_and_announced(): void
    {
        $this->metadata_repository->seed('uid-1', 42, CfStreamStatus::Processing);

        $this->updater->handle('uid-1', CfStreamStatus::Error, null);

        self::assertSame(CfStreamStatus::Error, $this->metadata_repository->status_for(42));
        self::assertSame(
            [
                [
                    'hook'    => EventCatalog::VIDEO_STREAM_STATUS_CHANGED,
                    'payload' => [
                        'video_id' => 42,
                        'status'   => 'error',
                    ],
                ],
            ],
            $this->hook_bus->dispatched
        );
    }
}
