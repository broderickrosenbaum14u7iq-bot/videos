<?php
/**
 * Tube-core's theme-facing template tags (ARCHITECTURE.md §5).
 *
 * Global functions, not class methods — the same reasoning
 * tube-player's/tube-search's `includes/template-tags.php` already
 * document: this is the part of tube-core's public surface a theme
 * (Phase 8) actually calls. Every function here is a thin wrapper
 * delegating straight to `Tube_Core\Plugin`'s accessors or a plain
 * WordPress query-var read — no business logic lives here.
 *
 * No `ABSPATH` guard here — `tube-core.php` already exits before
 * `require_once`-ing this file.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

use Tube_Core\Content\Actor;
use Tube_Core\Content\Studio;
use Tube_Core\Plugin as Tube_Core_Plugin;
use Tube_Core\WatchHistory\VisitorToken;

/**
 * Find an actor by URL slug, per ARCHITECTURE.md §14.
 *
 * @param string $slug The actor's URL slug.
 *
 * @return Actor|null The actor, or null if unknown.
 */
function tube_core_get_actor_by_slug(string $slug): ?Actor
{
    return Tube_Core_Plugin::instance()->actor_repository()->find_by_slug($slug);
}

/**
 * The actor being viewed on the current `/actor/{slug}/` request, per
 * ARCHITECTURE.md §15.1 — resolved once by
 * `Tube_Core\Content\Routing\TermArchiveRouting::route_template()`, read
 * here via WordPress's own `get_query_var()` mechanism.
 *
 * @return Actor|null The current actor, or null outside an actor archive request.
 */
function tube_core_get_current_actor(): ?Actor
{
    $actor = get_query_var('tube_actor_object');

    return $actor instanceof Actor ? $actor : null;
}

/**
 * Find a studio by URL slug, per ARCHITECTURE.md §14.
 *
 * @param string $slug The studio's URL slug.
 *
 * @return Studio|null The studio, or null if unknown.
 */
function tube_core_get_studio_by_slug(string $slug): ?Studio
{
    return Tube_Core_Plugin::instance()->studio_repository()->find_by_slug($slug);
}

/**
 * The studio being viewed on the current `/studio/{slug}/` request. Same shape as tube_core_get_current_actor().
 *
 * @return Studio|null The current studio, or null outside a studio archive request.
 */
function tube_core_get_current_studio(): ?Studio
{
    $studio = get_query_var('tube_studio_object');

    return $studio instanceof Studio ? $studio : null;
}

/**
 * Paged listing of every actor, alphabetical by name — powers Phase 13's
 * actor directory page.
 *
 * @param int $limit  Maximum number of actors to return.
 * @param int $offset Number of actors to skip, for pagination.
 *
 * @return Actor[]
 */
function tube_core_list_actors(int $limit, int $offset = 0): array
{
    return Tube_Core_Plugin::instance()->actor_repository()->list_all($limit, $offset);
}

/**
 * Total number of actors — pairs with tube_core_list_actors() for pagination totals.
 */
function tube_core_count_actors(): int
{
    return Tube_Core_Plugin::instance()->actor_repository()->count_all();
}

/**
 * Paged listing of every studio, alphabetical by name — powers Phase
 * 13's studio directory page and mega menu.
 *
 * @param int $limit  Maximum number of studios to return.
 * @param int $offset Number of studios to skip, for pagination.
 *
 * @return Studio[]
 */
function tube_core_list_studios(int $limit, int $offset = 0): array
{
    return Tube_Core_Plugin::instance()->studio_repository()->list_all($limit, $offset);
}

/**
 * Total number of studios — pairs with tube_core_list_studios() for pagination totals.
 */
function tube_core_count_studios(): int
{
    return Tube_Core_Plugin::instance()->studio_repository()->count_all();
}

/**
 * Find every actor in `$actor_ids` in one batched query — resolves a
 * video grid's `SearchIndexRow::$actor_ids` into names/links for Phase
 * 13's video-card "starring" badges without one query per card.
 *
 * @param int[] $actor_ids The actor row IDs to fetch.
 *
 * @return array<int, Actor> Keyed by actor ID; an unknown ID is simply absent.
 */
function tube_core_get_actors(array $actor_ids): array
{
    return Tube_Core_Plugin::instance()->actor_repository()->find_many($actor_ids);
}

/**
 * Find every studio in `$studio_ids` in one batched query. Same shape as tube_core_get_actors().
 *
 * @param int[] $studio_ids The studio row IDs to fetch.
 *
 * @return array<int, Studio> Keyed by studio ID; an unknown ID is simply absent.
 */
function tube_core_get_studios(array $studio_ids): array
{
    return Tube_Core_Plugin::instance()->studio_repository()->find_many($studio_ids);
}

/**
 * A video's current real like count, per the mobile watch-page
 * redesign's Like system — a single indexed read, safe to call once per
 * single-video page render.
 *
 * @param int $video_id The video post ID.
 */
function tube_core_likes_total(int $video_id): int
{
    return Tube_Core_Plugin::instance()->video_statistics_repository()->likes_total($video_id);
}

/**
 * Whether the current viewer already has this video liked — the watch
 * page's initial UI state. Never creates a guest visitor-token cookie
 * (unlike `Tube_Core\Likes\LikeController`'s own POST handler, which is
 * allowed to): a plain GET page render must not set a cookie on what
 * would otherwise be a cacheable response, so a guest who has never
 * interacted yet (no `tube_visitor` cookie) is simply reported as not
 * having liked anything, which is always correct for them.
 *
 * @param int $video_id The video post ID.
 */
function tube_core_has_liked(int $video_id): bool
{
    $user_id = get_current_user_id();

    if (0 !== $user_id) {
        return Tube_Core_Plugin::instance()->like_repository()->has_liked($user_id, null, $video_id);
    }

    $visitor_token = (new VisitorToken())->current();

    if (null === $visitor_token) {
        return false;
    }

    return Tube_Core_Plugin::instance()->like_repository()->has_liked(null, $visitor_token, $video_id);
}

/**
 * Whether the current viewer already has this video saved ("Watch
 * Later"). Same never-creates-a-cookie posture as tube_core_has_liked() — see its docblock.
 *
 * @param int $video_id The video post ID.
 */
function tube_core_has_saved(int $video_id): bool
{
    $user_id = get_current_user_id();

    if (0 !== $user_id) {
        return Tube_Core_Plugin::instance()->saved_video_repository()->has_saved($user_id, null, $video_id);
    }

    $visitor_token = (new VisitorToken())->current();

    if (null === $visitor_token) {
        return false;
    }

    return Tube_Core_Plugin::instance()->saved_video_repository()->has_saved(null, $visitor_token, $video_id);
}
