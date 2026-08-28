<?php
/**
 * Merges a guest's like/save rows into a newly-authenticated member.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Engagement;

use Tube_Core\Likes\Repositories\LikeRepositoryInterface;
use Tube_Core\Saves\Repositories\SavedVideoRepositoryInterface;
use Tube_Core\Views\Repositories\VideoStatisticsRepositoryInterface;

/**
 * Merges a guest's like/save rows into a newly-authenticated member, per
 * the member system's Phase 26/27 requirements. Called once, immediately
 * after a successful login/registration/Google sign-in, from
 * `Tube_Core\Plugin::merge_guest_engagement_into_user()` — the public
 * accessor `tube-members` calls (`tube-members` never queries
 * `wp_tube_video_likes`/`wp_tube_saved_videos` directly, per the
 * project's "no plugin queries another plugin's tables" rule).
 *
 * Idempotent: merging the same visitor token twice (e.g. a retried
 * request) is safe — the second call simply finds no remaining guest
 * rows for that token and does nothing.
 */
final class GuestMergeService
{
    /**
     * Construct around the collaborators this service reads and writes through.
     *
     * @param LikeRepositoryInterface            $likes      The per-viewer like rows.
     * @param SavedVideoRepositoryInterface      $saves      The per-viewer save rows.
     * @param VideoStatisticsRepositoryInterface $statistics The per-video likes_total counter.
     */
    public function __construct(
        private readonly LikeRepositoryInterface $likes,
        private readonly SavedVideoRepositoryInterface $saves,
        private readonly VideoStatisticsRepositoryInterface $statistics
    ) {
    }

    /**
     * Merge every like and save the given guest visitor token holds into
     * $user_id's own likes/saves, deduplicating videos the member had
     * already independently liked/saved before this merge (correcting
     * `likes_total` for any such duplicate, since a duplicate like row
     * was counted twice when it should count once).
     *
     * @param string $visitor_token The guest's cookie token being merged away.
     * @param int    $user_id       The member account absorbing the guest's engagement.
     */
    public function merge(string $visitor_token, int $user_id): void
    {
        $like_result = $this->likes->merge_visitor_into_user($visitor_token, $user_id);

        foreach ($like_result['duplicate_video_ids'] as $video_id) {
            $this->statistics->decrement_likes($video_id);
        }

        $this->saves->merge_visitor_into_user($visitor_token, $user_id);
    }
}
