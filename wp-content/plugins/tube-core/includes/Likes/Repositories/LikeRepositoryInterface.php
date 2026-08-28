<?php
/**
 * Contract for wp_tube_video_likes data access.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Likes\Repositories;

/**
 * Contract for `wp_tube_video_likes` data access, per the
 * `{Noun}Repository` convention (ARCHITECTURE.md §19.4).
 *
 * Every method takes exactly one of `$user_id`/`$visitor_token` (the
 * other null) — the same one-of-two-identities shape
 * `WatchHistoryRepositoryInterface` already establishes for guest vs.
 * logged-in viewers.
 */
interface LikeRepositoryInterface
{
    /**
     * Whether this viewer currently has an active like on this video.
     *
     * @param int|null    $user_id       The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id      The video to check.
     */
    public function has_liked(?int $user_id, ?string $visitor_token, int $video_id): bool;

    /**
     * Add a like row for this viewer/video, if one doesn't already
     * exist. Race-safe by construction: a concurrent duplicate insert is
     * rejected by `wp_tube_video_likes`'s own `UNIQUE KEY`
     * (`Migration011CreateVideoLikesTable`), not by an application-level
     * check-then-write.
     *
     * @param int|null    $user_id       The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id      The video being liked.
     *
     * @return bool True if a new row was actually inserted (a genuinely new like); false if one already existed.
     */
    public function add(?int $user_id, ?string $visitor_token, int $video_id): bool;

    /**
     * Remove this viewer's like row for this video, if one exists.
     *
     * @param int|null    $user_id       The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id      The video being unliked.
     *
     * @return bool True if a row was actually deleted; false if none existed.
     */
    public function remove(?int $user_id, ?string $visitor_token, int $video_id): bool;

    /**
     * Reassign every one of a guest's like rows to a newly-logged-in/
     * registered member, per the member system's Phase 27 ("Logged-in
     * member likes should use user ID as canonical identity... On login:
     * avoid accidental double-like from guest + member identity").
     *
     * For a video the guest liked where $user_id has no row yet, the
     * existing guest row is repointed in place (`visitor_token` cleared,
     * `user_id` set) — the like is preserved, `likes_total` is untouched
     * (it was already counted once, and remains counted once).
     *
     * For a video where BOTH a guest row and a $user_id row already
     * exist (the member liked the same video again after logging in on
     * another device, before ever merging), the guest row is deleted
     * outright and its video_id is reported back as a duplicate — the
     * caller (`Tube_Core\Engagement\GuestMergeService`) is responsible
     * for decrementing that video's `likes_total` by one, since this
     * repository has no access to `VideoStatisticsRepositoryInterface`.
     *
     * @param string $visitor_token The guest's cookie token being merged away.
     * @param int    $user_id       The member account absorbing the guest's likes.
     *
     * @return array{merged_video_ids: list<int>, duplicate_video_ids: list<int>}
     */
    public function merge_visitor_into_user(string $visitor_token, int $user_id): array;
}
