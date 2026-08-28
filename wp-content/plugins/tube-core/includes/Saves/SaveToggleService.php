<?php
/**
 * Toggles one viewer's "Watch Later" save on one video.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Saves;

use Tube_Core\Saves\Repositories\SavedVideoRepositoryInterface;

/**
 * Toggles one viewer's "Watch Later" save on one video — the pure logic
 * behind `SaveController`, same shape as `LikeToggleService` minus a
 * counter (nothing displays a save count, see the mobile watch-page
 * redesign's Part 11).
 */
final class SaveToggleService
{
    /**
     * Construct around the repository this service reads and writes through.
     *
     * @param SavedVideoRepositoryInterface $saves The per-viewer save rows.
     */
    public function __construct(private readonly SavedVideoRepositoryInterface $saves)
    {
    }

    /**
     * Flip this viewer's save state for this video.
     *
     * @param int|null    $user_id       The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id      The video being saved/unsaved.
     *
     * @return bool The resulting "saved" state, after the toggle.
     */
    public function toggle(?int $user_id, ?string $visitor_token, int $video_id): bool
    {
        if ($this->saves->has_saved($user_id, $visitor_token, $video_id)) {
            $this->saves->remove($user_id, $visitor_token, $video_id);

            return false;
        }

        $this->saves->add($user_id, $visitor_token, $video_id);

        return true;
    }
}
