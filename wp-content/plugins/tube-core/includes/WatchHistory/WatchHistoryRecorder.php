<?php
/**
 * Records watch progress for a guest or a logged-in user.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\WatchHistory;

use InvalidArgumentException;
use Tube_Core\WatchHistory\Repositories\WatchHistoryRepositoryInterface;

/**
 * Records watch progress for a guest or a logged-in user — the pure
 * logic behind the watch-history REST endpoint (ARCHITECTURE.md §12
 * Phase 5), separated from `WatchHistoryController`'s HTTP/cookie
 * concerns the same way this project splits every WordPress-coupled
 * boundary from its testable core.
 */
final class WatchHistoryRecorder
{
    /**
     * Construct around the repository progress is written to.
     *
     * @param WatchHistoryRepositoryInterface $repository Written to.
     */
    public function __construct(private readonly WatchHistoryRepositoryInterface $repository)
    {
    }

    /**
     * Record progress for exactly one of a logged-in user or a guest —
     * never both, never neither.
     *
     * @param int|null    $user_id          The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token    The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id         The video being watched.
     * @param int         $progress_seconds How far into the video the viewer has watched.
     * @param bool        $completed        Whether the viewer finished the video.
     *
     * @throws InvalidArgumentException If neither or both of $user_id/$visitor_token are given.
     */
    public function record(
        ?int $user_id,
        ?string $visitor_token,
        int $video_id,
        int $progress_seconds,
        bool $completed
    ): void {
        if (null !== $user_id && null !== $visitor_token) {
            throw new InvalidArgumentException('Provide either $user_id or $visitor_token, not both.');
        }

        if (null !== $user_id) {
            $this->repository->upsert_for_user($user_id, $video_id, $progress_seconds, $completed);

            return;
        }

        if (null !== $visitor_token) {
            $this->repository->upsert_for_guest($visitor_token, $video_id, $progress_seconds, $completed);

            return;
        }

        throw new InvalidArgumentException('Provide either $user_id or $visitor_token.');
    }
}
