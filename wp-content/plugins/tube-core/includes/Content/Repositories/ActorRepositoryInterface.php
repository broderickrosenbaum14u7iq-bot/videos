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
     * `wp_tube_video_actors` — a live `COUNT()`, not the
     * `wp_tube_actors.video_count` column (unmaintained before Phase 10;
     * see {@see self::replace_for_video()}/{@see self::bulk_add()}/
     * {@see self::bulk_remove()}, which start maintaining it). Used for
     * the actor archive page's pagination metadata.
     *
     * @param int $actor_id The actor's row ID.
     */
    public function count_videos_for_actor(int $actor_id): int;

    /**
     * Replace a single video's full set of actor assignments with exactly
     * `$actor_ids` — the write side of `tube-admin`'s per-video
     * actor-assignment UI (Phase 10). Diffs against the video's current
     * assignments so only the actual delta is written (a no-op call
     * writes nothing), and refreshes `wp_tube_actors.video_count` for
     * every actor added or removed. Bulk-inserts added rows in one
     * multi-row `INSERT`, per ARCHITECTURE.md §19.8 — never a loop of
     * single-row inserts.
     *
     * Does not dispatch any event — that is the caller's responsibility
     * (per ARCHITECTURE.md §6, events are fired by the orchestration layer
     * that owns a feature, not by a data-access repository).
     *
     * @param int   $video_id  The video post ID.
     * @param int[] $actor_ids The actor IDs this video should be assigned to; an empty array clears all assignments.
     */
    public function replace_for_video(int $video_id, array $actor_ids): void;

    /**
     * Add one or more actors to one or more videos, without touching any
     * of those videos' other existing actor assignments — the write side
     * of `tube-admin`'s bulk-assignment tool (Phase 10). Existing
     * (video, actor) pairs are left untouched (`INSERT IGNORE`, not an
     * error). Bulk-inserts every new pair in one multi-row `INSERT`, per
     * ARCHITECTURE.md §19.8. Refreshes `wp_tube_actors.video_count` for
     * every actor in `$actor_ids`.
     *
     * @param int[] $video_ids The video post IDs to add actors to.
     * @param int[] $actor_ids The actor IDs to add.
     *
     * @return int The number of (video, actor) rows actually inserted (excludes pairs that already existed).
     */
    public function bulk_add(array $video_ids, array $actor_ids): int;

    /**
     * Remove one or more actors from one or more videos — the write side
     * of `tube-admin`'s bulk-unassignment tool (Phase 10). Refreshes
     * `wp_tube_actors.video_count` for every actor in `$actor_ids`.
     *
     * @param int[] $video_ids The video post IDs to remove actors from.
     * @param int[] $actor_ids The actor IDs to remove.
     *
     * @return int The number of (video, actor) relationship rows actually deleted.
     */
    public function bulk_remove(array $video_ids, array $actor_ids): int;

    /**
     * Paged listing of every actor, alphabetical by name — the picker
     * `tube-admin`'s actor-assignment/bulk-tools screens (Phase 10) list
     * existing actors from.
     *
     * @param int $limit  Maximum number of actors to return.
     * @param int $offset Number of actors to skip, for pagination.
     *
     * @return Actor[]
     */
    public function list_all(int $limit, int $offset): array;

    /**
     * Total number of rows in `wp_tube_actors` — pairs with
     * {@see self::list_all()} for pagination totals.
     */
    public function count_all(): int;

    /**
     * Create a new actor. The slug is derived from `$name` and
     * disambiguated with the new row's own ID (`sanitize_title($name) .
     * '-' . $id`), the same scheme this project's own integration test
     * seeding already used before a write API existed — no separate
     * uniqueness-checking loop needed, since appending a just-assigned
     * auto-increment ID can never collide.
     *
     * @param string      $name The actor's display name.
     * @param string|null $bio  The actor's biography, if any.
     *
     * @return int The new actor's row ID.
     */
    public function create(string $name, ?string $bio): int;
}
