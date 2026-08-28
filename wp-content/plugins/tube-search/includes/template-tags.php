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

use Tube_Search\Discovery\ArchivePage;
use Tube_Search\Index\CandidateColumn;
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
 * One video's own indexed display row (`views_total`,
 * `duration_seconds`, `published_at`) — the mobile watch-page redesign's
 * single source for the meta row (views/duration), the same denormalized
 * `wp_tube_search_index` read every other display surface (hero, video
 * cards) already uses, rather than a second, parallel lookup against
 * tube-core's statistics/metadata tables for the same numbers.
 *
 * @param int $video_id The video post ID.
 *
 * @return SearchIndexRow|null The row, or null if this video isn't indexed.
 */
function tube_search_get_video(int $video_id): ?SearchIndexRow
{
    return Tube_Search_Plugin::instance()->search_index_repository()->find($video_id);
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

/**
 * The raw query text for the current `/search/{query}/` request, per
 * ARCHITECTURE.md §15.1 — resolved by
 * `Tube_Search\Search\SearchRouting::route_template()`, read here via
 * WordPress's own `get_query_var()` mechanism (the same pattern
 * `tube-core`'s `tube_core_get_current_actor()` already establishes).
 *
 * **Still percent-encoded** — `SearchRouting`'s rewrite-rule capture
 * (`$matches[1]`) is the raw URL path segment as it appeared in the
 * request; WordPress does not urldecode a custom, non-core rewrite
 * capture the way a real `?query=string` value gets decoded. This is the
 * correct value to feed straight into `tube_search_query()`'s matching
 * (unchanged — do not decode before searching) and into a
 * `rawurlencode()`-built pagination URL (re-encoding an already-decoded
 * value once is correct; re-encoding this raw value would double-encode
 * it). For anything a human actually reads — the search input's value,
 * a results heading, a `<title>`/meta description — use
 * {@see tube_search_current_query_display()} instead.
 *
 * @return string The raw, still-percent-encoded query text, or '' outside a search results request.
 */
function tube_search_current_query(): string
{
    $query = get_query_var('tube_search_q');

    return is_string($query) ? $query : '';
}

/**
 * The current `/search/{query}/` request's query text, decoded for
 * human-facing display — the search input's value, a results heading, an
 * SEO `<title>`/meta description. Decodes {@see tube_search_current_query()}'s
 * raw, still-percent-encoded value exactly once (`rawurldecode()`, the
 * exact counterpart of the `encodeURIComponent()` the search form's own
 * JS used to build this URL in the first place — never `urldecode()`,
 * which would also turn a literal `+` a visitor searched for into a
 * space, wrong for a URL path segment) — never looped or re-applied, so
 * a query that happens to contain a literal `%` character (e.g. "50%
 * off") is never corrupted by a second decode pass. Trimmed the same way
 * `Tube_Search\Search\SearchQuery::search()` already trims before
 * matching, so the displayed text matches what was actually searched
 * for.
 *
 * Deliberately a distinct function from {@see tube_search_current_query()},
 * not a parameter/flag on it: the raw value still has real, different
 * callers (matching, URL-building) that must never receive a decoded
 * value — see that function's own docblock.
 *
 * @return string The decoded, trimmed query text, or '' outside a search results request.
 */
function tube_search_current_query_display(): string
{
    return trim(rawurldecode(tube_search_current_query()));
}

/**
 * One page of a category archive listing, per ARCHITECTURE.md §15.1.
 *
 * @param int $term_id  The `video_category` term ID.
 * @param int $page     Which page of results to return (1-indexed).
 * @param int $per_page Results per page.
 */
function tube_search_by_category(int $term_id, int $page = 1, int $per_page = 24): ArchivePage
{
    return Tube_Search_Plugin::instance()->archive_videos_query()->get(
        CandidateColumn::CategoryIds,
        $term_id,
        $page,
        $per_page
    );
}

/**
 * One page of a tag archive listing, per ARCHITECTURE.md §15.1.
 *
 * @param int $term_id  The `video_tag` term ID.
 * @param int $page     Which page of results to return (1-indexed).
 * @param int $per_page Results per page.
 */
function tube_search_by_tag(int $term_id, int $page = 1, int $per_page = 24): ArchivePage
{
    return Tube_Search_Plugin::instance()->archive_videos_query()->get(
        CandidateColumn::TagIds,
        $term_id,
        $page,
        $per_page
    );
}

/**
 * One page of an actor archive listing, per ARCHITECTURE.md §15.1.
 *
 * @param int $actor_id The actor's row ID (`Tube_Core\Content\Actor::$id`).
 * @param int $page     Which page of results to return (1-indexed).
 * @param int $per_page Results per page.
 */
function tube_search_by_actor(int $actor_id, int $page = 1, int $per_page = 24): ArchivePage
{
    return Tube_Search_Plugin::instance()->archive_videos_query()->get(
        CandidateColumn::ActorIds,
        $actor_id,
        $page,
        $per_page
    );
}

/**
 * One page of a studio archive listing, per ARCHITECTURE.md §15.1.
 *
 * @param int $studio_id The studio's row ID (`Tube_Core\Content\Studio::$id`).
 * @param int $page      Which page of results to return (1-indexed).
 * @param int $per_page  Results per page.
 */
function tube_search_by_studio(int $studio_id, int $page = 1, int $per_page = 24): ArchivePage
{
    return Tube_Search_Plugin::instance()->archive_videos_query()->get(
        CandidateColumn::StudioIds,
        $studio_id,
        $page,
        $per_page
    );
}
