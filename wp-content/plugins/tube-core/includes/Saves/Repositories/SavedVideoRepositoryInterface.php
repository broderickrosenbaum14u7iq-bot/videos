<?php
/**
 * Contract for wp_tube_saved_videos data access.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Saves\Repositories;

/**
 * Contract for `wp_tube_saved_videos` ("Watch Later") data access, per
 * the `{Noun}Repository` convention (ARCHITECTURE.md §19.4). Same
 * one-of-two-identities shape as `LikeRepositoryInterface` — see its
 * docblock.
 */
interface SavedVideoRepositoryInterface
{
    /**
     * Whether this viewer currently has this video saved.
     *
     * @param int|null    $user_id       The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id      The video to check.
     */
    public function has_saved(?int $user_id, ?string $visitor_token, int $video_id): bool;

    /**
     * Save this video for this viewer, if not already saved. Race-safe
     * by construction via `wp_tube_saved_videos`'s own `UNIQUE KEY`
     * (`Migration013CreateSavedVideosTable`) — see
     * `LikeRepositoryInterface::add()`'s identical reasoning.
     *
     * @param int|null    $user_id       The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id      The video being saved.
     *
     * @return bool True if a new row was actually inserted; false if it was already saved.
     */
    public function add(?int $user_id, ?string $visitor_token, int $video_id): bool;

    /**
     * Remove this viewer's save for this video, if any.
     *
     * @param int|null    $user_id       The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id      The video being unsaved.
     *
     * @return bool True if a row was actually deleted; false if none existed.
     */
    public function remove(?int $user_id, ?string $visitor_token, int $video_id): bool;

    /**
     * Reassign every one of a guest's save rows to a newly-logged-in/
     * registered member, per the member system's Phase 26 ("offer/
     * perform safe merge into account saves, deduplicate, do not lose
     * guest saves"). Same shape as
     * `LikeRepositoryInterface::merge_visitor_into_user()` — see its
     * docblock for the full reasoning. Saves carry no counter, so a
     * duplicate (both a guest row and an existing user row for the same
     * video) is simply deleted with no further caller action needed.
     *
     * @param string $visitor_token The guest's cookie token being merged away.
     * @param int    $user_id       The member account absorbing the guest's saves.
     *
     * @return array{merged_video_ids: list<int>, duplicate_video_ids: list<int>}
     */
    public function merge_visitor_into_user(string $visitor_token, int $user_id): array;

    /**
     * This member's saved video IDs, most-recently-saved first — the
     * frontend account page's "Video đã lưu" section (Phase 9). Public
     * so `tube-members` can call it via `Tube_Core\Plugin::instance()->
     * saved_video_repository()` (never a direct `wp_tube_saved_videos`
     * query from tube-members, per the project's "no plugin queries
     * another plugin's tables" rule).
     *
     * @param int $user_id The member account.
     * @param int $limit   Maximum number of video IDs to return.
     *
     * @return list<int>
     */
    public function video_ids_for_user(int $user_id, int $limit): array;
}
