<?php
/**
 * Unit tests for WatchHistoryRecorder.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\WatchHistory;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tube_Core\Tests\Unit\WatchHistory\Fixtures\InMemoryWatchHistoryRepository;
use Tube_Core\WatchHistory\WatchHistoryRecorder;

/**
 * Exercises WatchHistoryRecorder against a fake repository — no database.
 */
final class WatchHistoryRecorderTest extends TestCase
{
    /**
     * The fake repository the recorder under test writes to.
     *
     * @var InMemoryWatchHistoryRepository
     */
    private InMemoryWatchHistoryRepository $repository;

    /**
     * The recorder under test.
     *
     * @var WatchHistoryRecorder
     */
    private WatchHistoryRecorder $recorder;

    /**
     * Build a fresh recorder and fake for each test.
     */
    protected function setUp(): void
    {
        $this->repository = new InMemoryWatchHistoryRepository();
        $this->recorder   = new WatchHistoryRecorder($this->repository);
    }

    /**
     * A logged-in user's progress goes through upsert_for_user().
     */
    public function test_a_logged_in_user_goes_through_upsert_for_user(): void
    {
        $this->recorder->record(7, null, 42, 120, false);

        self::assertSame(
            [
                [
                    'user_id'          => 7,
                    'video_id'         => 42,
                    'progress_seconds' => 120,
                    'completed'        => false,
                ],
            ],
            $this->repository->upsert_for_user_calls
        );
        self::assertSame([], $this->repository->upsert_for_guest_calls);
    }

    /**
     * A guest's progress goes through upsert_for_guest().
     */
    public function test_a_guest_goes_through_upsert_for_guest(): void
    {
        $this->recorder->record(null, 'visitor-token-123', 42, 60, true);

        self::assertSame(
            [
                [
                    'visitor_token'    => 'visitor-token-123',
                    'video_id'         => 42,
                    'progress_seconds' => 60,
                    'completed'        => true,
                ],
            ],
            $this->repository->upsert_for_guest_calls
        );
        self::assertSame([], $this->repository->upsert_for_user_calls);
    }

    /**
     * Providing neither a user ID nor a visitor token is rejected.
     */
    public function test_rejects_neither_user_nor_guest(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->recorder->record(null, null, 42, 60, false);
    }

    /**
     * Providing both a user ID and a visitor token is rejected.
     */
    public function test_rejects_both_user_and_guest(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->recorder->record(7, 'visitor-token-123', 42, 60, false);
    }
}
