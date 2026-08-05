<?php
/**
 * Contract for wp_tube_studios/wp_tube_video_studios data access.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Content\Repositories;

use Tube_Core\Content\Studio;

/**
 * Contract for `wp_tube_studios`/`wp_tube_video_studios` data access,
 * per the `{Noun}Repository` convention (ARCHITECTURE.md §19.4) — the
 * same shape as `ActorRepositoryInterface`, see its docblock for why
 * this wasn't built until Phase 8.
 */
interface StudioRepositoryInterface
{
    /**
     * Find a studio by ID.
     *
     * @param int $studio_id The studio's row ID.
     *
     * @return Studio|null The studio, or null if no row exists for this ID.
     */
    public function find(int $studio_id): ?Studio;

    /**
     * Find a studio by its URL slug — the lookup the `/studio/{slug}/` route uses.
     *
     * @param string $slug The studio's URL slug.
     *
     * @return Studio|null The studio, or null if no row has this slug.
     */
    public function find_by_slug(string $slug): ?Studio;

    /**
     * The studio IDs a video is currently assigned to, per `wp_tube_video_studios`.
     *
     * @param int $video_id The video post ID.
     *
     * @return int[]
     */
    public function studio_ids_for_video(int $video_id): array;

    /**
     * The number of videos currently assigned to a studio, per
     * `wp_tube_video_studios` — a live `COUNT()`, not the (currently
     * unmaintained) `wp_tube_studios.video_count` column.
     *
     * @param int $studio_id The studio's row ID.
     */
    public function count_videos_for_studio(int $studio_id): int;
}
