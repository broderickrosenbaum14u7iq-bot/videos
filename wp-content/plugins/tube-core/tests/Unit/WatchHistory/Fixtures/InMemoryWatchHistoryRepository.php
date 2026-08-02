<?php
/**
 * Test fixture: an in-memory WatchHistoryRepositoryInterface.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\WatchHistory\Fixtures;

use Tube_Core\WatchHistory\Repositories\WatchHistoryRepositoryInterface;

/**
 * An in-memory WatchHistoryRepositoryInterface that records what it was
 * asked to do — no database.
 */
final class InMemoryWatchHistoryRepository implements WatchHistoryRepositoryInterface
{
    /**
     * Every upsert_for_user() call this fake received, in order.
     *
     * @var list<array{user_id: int, video_id: int, progress_seconds: int, completed: bool}>
     */
    public array $upsert_for_user_calls = [];

    /**
     * Every upsert_for_guest() call this fake received, in order.
     *
     * @var list<array{visitor_token: string, video_id: int, progress_seconds: int, completed: bool}>
     */
    public array $upsert_for_guest_calls = [];

    /**
     * Every purge_stale_guests() cutoff this fake received, in order.
     *
     * @var list<string>
     */
    public array $purge_stale_guests_calls = [];

    /**
     * What purge_stale_guests() should return.
     *
     * @var int
     */
    public int $purge_stale_guests_return = 0;

    /**
     * {@inheritDoc}
     *
     * @param int  $user_id          The logged-in user's ID.
     * @param int  $video_id         The video being watched.
     * @param int  $progress_seconds How far into the video the viewer has watched.
     * @param bool $completed        Whether the viewer finished the video.
     */
    public function upsert_for_user(int $user_id, int $video_id, int $progress_seconds, bool $completed): void
    {
        $this->upsert_for_user_calls[] = [
            'user_id'          => $user_id,
            'video_id'         => $video_id,
            'progress_seconds' => $progress_seconds,
            'completed'        => $completed,
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @param string $visitor_token    The guest's visitor token.
     * @param int    $video_id         The video being watched.
     * @param int    $progress_seconds How far into the video the viewer has watched.
     * @param bool   $completed        Whether the viewer finished the video.
     */
    public function upsert_for_guest(string $visitor_token, int $video_id, int $progress_seconds, bool $completed): void
    {
        $this->upsert_for_guest_calls[] = [
            'visitor_token'    => $visitor_token,
            'video_id'         => $video_id,
            'progress_seconds' => $progress_seconds,
            'completed'        => $completed,
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @param string $cutoff MySQL `DATETIME` string.
     */
    public function purge_stale_guests(string $cutoff): int
    {
        $this->purge_stale_guests_calls[] = $cutoff;

        return $this->purge_stale_guests_return;
    }
}
