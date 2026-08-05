<?php
/**
 * Contract for wp_tube_actors/wp_tube_video_actors data access.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Content\Repositories;

use Tube_Core\Content\Actor;

/**
 * Contract for `wp_tube_actors`/`wp_tube_video_actors` data access, per
 * the `{Noun}Repository` convention (ARCHITECTURE.md §19.4).
 *
 * Not built in Phase 1 (which only created the tables via migration,
 * per §14) — this is the first real consumer, needed by Phase 8's actor
 * archive page (profile lookup + URL routing) and by `tube-search`'s
 * `VideoIndexer`, which reads `actor_ids_for_video()` to keep the
 * denormalized search index's `actor_ids` column in sync with the real
 * relationship table, the same way it already reads
 * `wp_get_post_terms()` for category/tag.
 *
 * Adopted per the interface-justification rule (§19.1) on the same
 * grounds as every other dedicated-table repository in this codebase
 * (`VideoMetadataRepositoryInterface`, `VideoStatisticsRepositoryInterface`,
 * etc.): a genuine cross-plugin boundary — `tube-search`'s `VideoIndexer`
 * depends on this interface, not the concrete class, the same shape
 * `VideoMetadataRepositoryInterface` already established for
 * `tube-player`'s cross-plugin dependency. `TermArchiveRouting`'s own
 * dependency is a plain `Closure` instead (see its docblock for why), so
 * this interface's payoff is that one real cross-plugin consumer, not a
 * unit-test fake — nothing in this codebase currently unit-tests against
 * a fake of this interface, and this docblock doesn't claim otherwise.
 */
interface ActorRepositoryInterface
{
    /**
     * Find an actor by ID.
     *
     * @param int $actor_id The actor's row ID.
     *
     * @return Actor|null The actor, or null if no row exists for this ID.
     */
    public function find(int $actor_id): ?Actor;

    /**
     * Find an actor by its URL slug — the lookup the `/actor/{slug}/` route uses.
     *
     * @param string $slug The actor's URL slug.
     *
     * @return Actor|null The actor, or null if no row has this slug.
     */
    public function find_by_slug(string $slug): ?Actor;

    /**
     * The actor IDs a video is currently assigned to, per `wp_tube_video_actors`.
     *
     * @param int $video_id The video post ID.
     *
     * @return int[]
     */
    public function actor_ids_for_video(int $video_id): array;

    /**
     * The number of videos currently assigned to an actor, per
     * `wp_tube_video_actors` — a live `COUNT()`, not the (currently
     * unmaintained) `wp_tube_actors.video_count` column. Used for the
     * actor archive page's pagination metadata.
     *
     * @param int $actor_id The actor's row ID.
     */
    public function count_videos_for_actor(int $actor_id): int;
}
