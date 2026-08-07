<?php
/**
 * A video grid, optionally paginated — the one place the
 * "prime + grid + pagination" block lives, consolidated in Phase 13 from
 * 7 near-identical copies (`front-page.php`, `search.php`,
 * `template-parts/archive-listing.php`, `page-templates/{trending,
 * most-viewed,latest}.php`, `single-video.php`'s related-videos block,
 * `taxonomy-video_category.php`/`taxonomy-video_tag.php` via
 * `archive-listing.php`). This is what gives Phase 13's infinite-scroll
 * JS one stable markup contract instead of several independently-
 * drifting copies (`assets/js/tube-theme.js`'s infinite-scroll code
 * looks for exactly the `data-tube-*` attributes below).
 *
 * Expects, via `get_template_part()`'s `$args`:
 * - `videos` (Tube_Search\Index\SearchIndexRow[]) — required.
 * - `empty_message` (string) — optional; shown when `videos` is empty. If
 *   omitted, an empty `videos` renders nothing at all (the caller has
 *   already decided whether to show this section for an empty result,
 *   e.g. a homepage row that simply omits itself).
 * - `page` (int), `total_pages` (int), `page_url` (callable(int): string)
 *   — optional, all three together or not at all. When present, the grid
 *   is wraped for infinite scroll (per ARCHITECTURE.md §15.2 — progressive
 *   enhancement over real paginated URLs, never a distinct AJAX-only
 *   state) and real pagination links are rendered via
 *   `template-parts/pagination.php`. When absent, this is a flat,
 *   unpaginated list (homepage rows, Trending/Most-Viewed/Latest pages,
 *   related videos) — none of tube-search's backing queries for those
 *   take a page parameter, so there is nothing to paginate.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

use Tube_Search\Index\SearchIndexRow;

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $args */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

if (!isset($args['videos']) || !is_array($args['videos'])) {
    return;
}

/** @var SearchIndexRow[] $tube_theme_videos */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_videos = $args['videos'];

$tube_theme_empty_message = isset($args['empty_message']) && is_string($args['empty_message'])
    ? $args['empty_message']
    : '';

if ([] === $tube_theme_videos) {
    if ('' !== $tube_theme_empty_message) {
        echo '<p>' . esc_html($tube_theme_empty_message) . '</p>';
    }

    return;
}

$tube_theme_is_paginated = isset($args['page'], $args['total_pages'], $args['page_url'])
    && is_int($args['page'])
    && is_int($args['total_pages'])
    && is_callable($args['page_url']);

tube_theme_prime_video_grid($tube_theme_videos);

?>

<div<?php echo $tube_theme_is_paginated ? ' class="listing" data-tube-infinite-scroll' : ''; ?>>
    <div class="video-grid" data-tube-video-grid>
        <?php foreach ($tube_theme_videos as $tube_theme_video) : ?>
            <?php get_template_part('template-parts/video-card', null, ['video' => $tube_theme_video]); ?>
        <?php endforeach; ?>
    </div>

    <?php if ($tube_theme_is_paginated) : ?>
        <div data-tube-pagination>
            <?php
            get_template_part(
                'template-parts/pagination',
                null,
                [
                    'page'        => $args['page'],
                    'total_pages' => $args['total_pages'],
                    'page_url'    => $args['page_url'],
                ]
            );
            ?>
        </div>
    <?php endif; ?>
</div>
