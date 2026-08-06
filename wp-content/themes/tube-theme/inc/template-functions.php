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
