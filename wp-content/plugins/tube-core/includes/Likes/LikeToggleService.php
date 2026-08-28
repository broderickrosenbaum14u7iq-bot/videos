<?php
/**
 * Toggles one viewer's like on one video, keeping likes_total in sync.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Likes;

use Tube_Core\Likes\Repositories\LikeRepositoryInterface;
use Tube_Core\Views\Repositories\VideoStatisticsRepositoryInterface;

/**
 * Toggles one viewer's like on one video, per the mobile watch-page
 * redesign's Like system — the pure logic behind `LikeController`,
 * separated from its HTTP/cookie concerns the same way this project
 * splits every WordPress-coupled boundary from its testable core
 * (`WatchHistoryRecorder` is the closest precedent).
 *
 * `likes_total` is only ever incremented/decremented when
 * `LikeRepositoryInterface::add()`/`remove()` report a row was actually
 * inserted/deleted — never unconditionally alongside every call — which
 * is what keeps the counter race-safe under a concurrent double-tap: two
 * simultaneous "like" requests for the same viewer/video can both reach
 * this service, but `wp_tube_video_likes`'s `UNIQUE KEY` (via `add()`'s
 * `INSERT IGNORE`) guarantees only one of them actually inserts a row,
 * so only one increments the counter.
 */
final class LikeToggleService
{
    /**
     * Construct around the collaborators this service reads and writes through.
     *
     * @param LikeRepositoryInterface            $likes      The per-viewer like rows.
     * @param VideoStatisticsRepositoryInterface $statistics The per-video likes_total counter.
     */
    public function __construct(
        private readonly LikeRepositoryInterface $likes,
        private readonly VideoStatisticsRepositoryInterface $statistics
    ) {
    }

    /**
     * Flip this viewer's like state for this video: like if not
     * currently liked, unlike if currently liked.
     *
     * @param int|null    $user_id       The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id      The video being liked/unliked.
     *
     * @return array{liked: bool, likes_total: int} The resulting state, after the toggle.
     */
    public function toggle(?int $user_id, ?string $visitor_token, int $video_id): array
    {
        if ($this->likes->has_liked($user_id, $visitor_token, $video_id)) {
            if ($this->likes->remove($user_id, $visitor_token, $video_id)) {
                $this->statistics->decrement_likes($video_id);
            }

            return [
                'liked'       => false,
                'likes_total' => $this->statistics->likes_total($video_id),
            ];
        }

        if ($this->likes->add($user_id, $visitor_token, $video_id)) {
            $this->statistics->increment_likes($video_id);
        }

        return [
            'liked'       => true,
            'likes_total' => $this->statistics->likes_total($video_id),
        ];
    }
}
