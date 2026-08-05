<?php
/**
 * Search results page (`/search/{query}/`), routed by
 * `Tube_Search\Search\SearchRouting` — a custom rewrite, not WordPress
 * core's native `?s=` search (ARCHITECTURE.md §15.1).
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$tube_theme_query = tube_search_current_query();
$tube_theme_page  = tube_theme_current_page();
$tube_theme_items = tube_search_query(
    [
        'q'    => $tube_theme_query,
        'page' => $tube_theme_page,
    ]
);

?>

<h1>
    <?php
    printf(
        /* translators: %s: the search query text. */
        esc_html__('Search results for "%s"', 'tube-theme'),
        esc_html($tube_theme_query)
    );
    ?>
</h1>

<?php if ([] === $tube_theme_items) : ?>
    <p><?php esc_html_e('No videos matched your search.', 'tube-theme'); ?></p>
<?php else : ?>
    <div class="video-grid">
        <?php foreach ($tube_theme_items as $tube_theme_video) : ?>
            <?php get_template_part('template-parts/video-card', null, ['video' => $tube_theme_video]); ?>
        <?php endforeach; ?>
    </div>

    <?php
    // Total isn't known here (tube_search_query() returns only this
    // page's items) -- "another page exists" is inferred from a full
    // page of results, the simplest correct signal without a second
    // COUNT() query search results don't otherwise need.
    $tube_theme_total_pages = count($tube_theme_items) < 20 ? $tube_theme_page : $tube_theme_page + 1;

    get_template_part(
        'template-parts/pagination',
        null,
        [
            'page'        => $tube_theme_page,
            'total_pages' => $tube_theme_total_pages,
            'page_url'    => static fn (int $tube_theme_target_page): string =>
                home_url('/search/' . rawurlencode($tube_theme_query) . '/')
                    . ($tube_theme_target_page > 1 ? 'page/' . $tube_theme_target_page . '/' : ''),
        ]
    );
    ?>
<?php endif; ?>

<?php
get_footer();
