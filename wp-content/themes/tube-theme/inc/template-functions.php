<?php
/**
 * Small template-local helpers shared across this theme's page templates.
 *
 * No `ABSPATH` guard here — `functions.php` already exits before
 * `require_once`-ing this file (the same reasoning every plugin's own
 * `includes/template-tags.php` documents).
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

use Tube_Search\Index\SearchIndexRow;

/**
 * Batch-prime both WordPress's own post cache and tube-player's video-
 * metadata cache for a list of videos about to be rendered as
 * `template-parts/video-card`s — call once before the loop, not per card.
 *
 * Added in Phase 11 after an audit found every grid template
 * (`front-page.php`, `search.php`, `page-templates/*.php`,
 * `template-parts/archive-listing.php`, `single-video.php`'s related-
 * videos block) rendering `video-card.php` in a loop with no batching:
 * each card's `get_permalink()` and `tube_player_get_image_html()` call
 * independently queried `wp_posts`/`wp_tube_video_metadata`, an N-query-
 * per-page pattern on exactly the pages that receive the bulk of this
 * project's real traffic. `_prime_post_caches()` is the same technique
 * this project's plugins already use for this (e.g.
 * `Tube_Seo\Sitemap\SitemapGenerator`); `tube_player_prime_video_metadata()`
 * is tube-player's own new equivalent for its own table (Phase 11).
 *
 * Purely a performance optimization — every caller already works
 * correctly without this, just with one query per card instead of two
 * queries for the whole grid.
 *
 * Phase 13: also primes `tube-core`'s actor/studio repositories
 * (`ActorRepository`/`StudioRepository`'s own request-lifetime caches —
 * see their docblocks) with every actor/studio ID referenced anywhere in
 * this grid, via one batched `tube_core_get_actors()`/`_get_studios()`
 * call each. `template-parts/video-card.php`'s own, per-card calls to
 * those same functions (for its "starring" badge) then hit an already-
 * warmed cache instead of issuing a new query — the identical priming
 * shape `tube_player_prime_video_metadata()` already established for
 * video metadata.
 *
 * @param SearchIndexRow[] $videos The videos about to be rendered.
 */
function tube_theme_prime_video_grid(array $videos): void
{
    if ([] === $videos) {
        return;
    }

    $video_ids = array_map(static fn (SearchIndexRow $video): int => $video->video_id, $videos);

    _prime_post_caches($video_ids, false, false);
    tube_player_prime_video_metadata($video_ids);

    $actor_ids  = [];
    $studio_ids = [];

    foreach ($videos as $video) {
        array_push($actor_ids, ...$video->actor_ids);
        array_push($studio_ids, ...$video->studio_ids);
    }

    if ([] !== $actor_ids) {
        tube_core_get_actors($actor_ids);
    }

    if ([] !== $studio_ids) {
        tube_core_get_studios($studio_ids);
    }
}

/**
 * Format a video's duration as `M:SS` (or `H:MM:SS` past an hour) for
 * the video card's duration badge, or `''` if unknown.
 *
 * @param int|null $seconds `SearchIndexRow::$duration_seconds`.
 */
function tube_theme_format_duration(?int $seconds): string
{
    if (null === $seconds || $seconds < 0) {
        return '';
    }

    $hours   = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs    = $seconds % 60;

    return $hours > 0
        ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs)
        : sprintf('%d:%02d', $minutes, $secs);
}

/**
 * The current page number, from WordPress's own `paged` query var —
 * shared by every listing template so each one doesn't repeat the same
 * `is_numeric()` narrowing of `get_query_var()`'s untyped return.
 */
function tube_theme_current_page(): int
{
    $paged = get_query_var('paged');

    return is_numeric($paged) && (int) $paged > 0 ? (int) $paged : 1;
}

/**
 * The URL of the first published WordPress Page assigned a given page
 * template (e.g. `page-templates/trending.php`), or null if no such
 * Page exists yet.
 *
 * Trending/Most-Viewed/Latest (Phase 8) and the actor/studio directory
 * pages (Phase 13) are ordinary, editor-assigned WordPress Pages — per
 * `page-templates/trending.php`'s own docblock, this project's frozen
 * URL table (ARCHITECTURE.md §15.1) has no dedicated slug for them, so
 * their real URL is whatever an editor gives the Page they assign the
 * template to. The header/footer/mega-menu need to link to them without
 * hard-coding a slug.
 *
 * Every call (across header.php, footer.php — up to 5 distinct
 * templates per request) shares one bulk-resolved, request-lifetime map
 * (built by {@see tube_theme_resolve_page_template_urls()} on first
 * call) rather than each call issuing its own `get_posts()` query — this
 * runs on every single page load site-wide, so it's worth one query
 * instead of up to five.
 *
 * @param string $template The page template file, relative to the theme root (e.g. `page-templates/trending.php`).
 *
 * @return string|null The Page's permalink, or null if no published Page uses this template.
 */
function tube_theme_page_template_url(string $template): ?string
{
    /** @var array<string, string>|null $map */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
    static $map = null;

    if (null === $map) {
        $map = tube_theme_resolve_page_template_urls();
    }

    return $map[ $template ] ?? null;
}

/**
 * Resolve every published Page's assigned template into a
 * `template => permalink` map, in one query — the collaborator behind
 * {@see tube_theme_page_template_url()}.
 *
 * Native `get_posts()` against `wp_postmeta` (the same lookup WordPress
 * core's own page-template admin UI performs), not a dedicated-table
 * query this project's `$wpdb` rule (ARCHITECTURE.md §2.5) applies to.
 * `meta_compare => 'EXISTS'` (no `meta_value`) intentionally fetches
 * every custom-templated Page in one pass rather than one query per
 * template name — a real site has a small, bounded number of Pages with
 * a non-default template assigned, so this stays cheap regardless of
 * how many templates a caller ends up asking for.
 *
 * @return array<string, string> Template file => permalink. A template with no matching Page is simply absent.
 */
function tube_theme_resolve_page_template_urls(): array
{
    $pages = get_posts(
        [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_key'       => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_compare'   => 'EXISTS',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]
    );

    $map = [];

    foreach ($pages as $page) {
        $template = get_page_template_slug($page);

        if (!is_string($template) || '' === $template || isset($map[ $template ])) {
            continue;
        }

        $map[ $template ] = get_permalink($page);
    }

    return $map;
}
