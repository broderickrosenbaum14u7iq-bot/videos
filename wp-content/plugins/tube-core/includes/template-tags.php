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
