<?php
/**
 * Tube-search's theme-facing template tags (ARCHITECTURE.md §5).
 *
 * Global functions, not class methods — the same reasoning
 * tube-player's `includes/template-tags.php` documents: this is the one
 * part of tube-search's public surface a theme (Phase 8) actually calls,
 * so it follows WordPress's own template-tag convention. Every function
 * here is a thin wrapper delegating straight to `Tube_Search\Plugin`'s
 * query-class accessors — no business logic lives here, per this
 * phase's "expose only template-tag helpers, no business logic inside
 * the theme" instruction, applied to this file as much as to the theme
 * that will call it.
 *
 * No `ABSPATH` guard here — `tube-search.php` already exits before
 * `require_once`-ing this file (see `tube-player`'s identical file for
 * the same PSR-1-side-effects reasoning).
 *
 * @package Tube_Search
 */

declare(strict_types=1);

use Tube_Search\Index\SearchIndexRow;
use Tube_Search\Plugin as Tube_Search_Plugin;

/**
 * Related videos for one video, per ARCHITECTURE.md §12 Phase 7's
 * priority cascade (same categories, same actors, same studio, similar
 * tags, random fallback) — never includes the video itself.
 *
 * @param int $video_id The video to find related videos for.
 * @param int $limit    Maximum number of videos to return.
 *
 * @return SearchIndexRow[]
 */
function tube_search_related_videos(int $video_id, int $limit = 12): array
{
    return Tube_Search_Plugin::instance()->related_videos_finder()->find($video_id, $limit);
}

/**
 * The videos with the highest recent (7-day) view count, per
 * ARCHITECTURE.md §12 Phase 7's "Trending" — read from tube-core's
 * precomputed statistics table, never a runtime aggregation.
 *
 * @param int $limit Maximum number of videos to return.
 *
 * @return SearchIndexRow[]
 */
function tube_search_trending(int $limit = 12): array
{
    return Tube_Search_Plugin::instance()->popular_videos_query()->trending($limit);
}

/**
 * The videos with the highest all-time view count, per ARCHITECTURE.md
 * §12 Phase 7's "Most Viewed" — read only from tube-core's precomputed
 * statistics table.
 *
 * @param int $limit Maximum number of videos to return.
 *
 * @return SearchIndexRow[]
 */
function tube_search_most_viewed(int $limit = 12): array
{
    return Tube_Search_Plugin::instance()->popular_videos_query()->most_viewed($limit);
}

/**
 * The most recently published videos, per ARCHITECTURE.md §12 Phase 7's
 * "Recently Added" — indexed publish-date ordering.
 *
 * @param int $limit Maximum number of videos to return.
 *
 * @return SearchIndexRow[]
 */
function tube_search_recently_added(int $limit = 12): array
{
    return Tube_Search_Plugin::instance()->recently_added_query()->get($limit);
}

/**
 * Full-text search, per ARCHITECTURE.md §5 — backed by
 * `wp_tube_search_index`, not a live join.
 *
 * @param array{q?: string, page?: int, per_page?: int} $args `q` (the query text, required for
 *                                                              any results), `page` (default 1),
 *                                                              `per_page` (default 20).
 *
 * @return SearchIndexRow[] Empty if `q` is missing/blank.
 */
function tube_search_query(array $args): array
{
    $query    = $args['q'] ?? '';
    $page     = $args['page'] ?? 1;
    $per_page = $args['per_page'] ?? 20;

    return Tube_Search_Plugin::instance()->search_query()->search($query, $page, $per_page);
}
