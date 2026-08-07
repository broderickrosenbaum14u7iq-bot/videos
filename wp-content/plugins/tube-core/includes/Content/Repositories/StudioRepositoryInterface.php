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
     * Find every studio in `$studio_ids` in one batched query — the bulk
     * counterpart to {@see self::find()}. Same shape and justification as
     * `ActorRepositoryInterface::find_many()`; see its docblock.
     *
     * @param int[] $studio_ids The studio row IDs to fetch.
     *
     * @return array<int, Studio> Keyed by studio ID; an ID with no matching row is simply absent, not null.
     */
    public function find_many(array $studio_ids): array;

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
     * `wp_tube_video_studios` — a live `COUNT()`, not the
     * `wp_tube_studios.video_count` column (unmaintained before Phase 10;
     * see {@see self::replace_for_video()}/{@see self::bulk_add()}/
     * {@see self::bulk_remove()}, which start maintaining it).
     *
     * @param int $studio_id The studio's row ID.
     */
    public function count_videos_for_studio(int $studio_id): int;

    /**
     * Replace a single video's full set of studio assignments with
     * exactly `$studio_ids` — the write side of `tube-admin`'s per-video
     * studio-assignment UI (Phase 10). Same shape as
     * `ActorRepositoryInterface::replace_for_video()`; see its docblock.
     *
     * @param int   $video_id   The video post ID.
     * @param int[] $studio_ids The studio IDs this video should be assigned to; an empty array clears all assignments.
     */
    public function replace_for_video(int $video_id, array $studio_ids): void;

    /**
     * Add one or more studios to one or more videos. Same shape as
     * `ActorRepositoryInterface::bulk_add()`; see its docblock.
     *
     * @param int[] $video_ids  The video post IDs to add studios to.
     * @param int[] $studio_ids The studio IDs to add.
     *
     * @return int The number of (video, studio) rows actually inserted (excludes pairs that already existed).
     */
    public function bulk_add(array $video_ids, array $studio_ids): int;

    /**
     * Remove one or more studios from one or more videos. Same shape as
     * `ActorRepositoryInterface::bulk_remove()`; see its docblock.
     *
     * @param int[] $video_ids  The video post IDs to remove studios from.
     * @param int[] $studio_ids The studio IDs to remove.
     *
     * @return int The number of (video, studio) relationship rows actually deleted.
     */
    public function bulk_remove(array $video_ids, array $studio_ids): int;

    /**
     * Paged listing of every studio, alphabetical by name. Same shape as
     * `ActorRepositoryInterface::list_all()`; see its docblock.
     *
     * @param int $limit  Maximum number of studios to return.
     * @param int $offset Number of studios to skip, for pagination.
     *
     * @return Studio[]
     */
    public function list_all(int $limit, int $offset): array;

    /**
     * Total number of rows in `wp_tube_studios`. Pairs with {@see self::list_all()}.
     */
    public function count_all(): int;

    /**
     * Create a new studio. Same slug-derivation scheme as
     * `ActorRepositoryInterface::create()`; see its docblock.
     *
     * @param string      $name        The studio's display name.
     * @param string|null $description The studio's description, if any.
     * @param string|null $website_url The studio's external website, if any.
     *
     * @return int The new studio's row ID.
     */
    public function create(string $name, ?string $description, ?string $website_url): int;
}
