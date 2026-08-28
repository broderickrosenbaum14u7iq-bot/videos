<?php
/**
 * Search results page (`/search/{query}/`), routed by
 * `Tube_Search\Search\SearchRouting` — a custom rewrite, not WordPress
 * core's native `?s=` search (ARCHITECTURE.md §15.1).
 *
 * Qualifies for infinite scroll (Phase 13 decision #4 — archives and
 * search have real, offset-aware pagination) via
 * `template-parts/video-grid.php`.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

get_header();

// The raw, still-percent-encoded query text — fed as-is into
// rawurlencode() below for pagination URLs (re-encoding an
// already-decoded value once is correct; this raw value is not decoded
// first, so those links are not this file's concern to fix here — see
// tube_search_current_query()'s own docblock).
$tube_theme_query = tube_search_current_query();

// The same query, decoded — used both for human display (the heading
// below) and as the real matching input (Tube_Search\Search\TextNormalizer::normalize()
// expects already-decoded UTF-8, not a percent-encoded value) — see
// tube_search_current_query_display()'s own docblock.
$tube_theme_query_display = tube_search_current_query_display();

$tube_theme_page = tube_theme_current_page();

// Matching needs real, decoded text too (Tube_Search\Search\TextNormalizer::normalize()
// expects already-decoded UTF-8 — see its own docblock) — $tube_theme_query_display
// is exactly that value, already computed above for the heading; reusing
// it here is a matching-input fix, not a change to how it's decoded.
$tube_theme_items = tube_search_query(
    [
        'q'    => $tube_theme_query_display,
        'page' => $tube_theme_page,
    ]
);

// Total isn't known here (tube_search_query() returns only this page's
// items) -- "another page exists" is inferred from a full page of
// results, the simplest correct signal without a second COUNT() query
// search results don't otherwise need.
$tube_theme_total_pages = count($tube_theme_items) < 20 ? $tube_theme_page : $tube_theme_page + 1;

?>

<h1 class="search-page__heading">
    <?php
    printf(
        /* translators: %s: the search query text. */
        esc_html__('Kết quả tìm kiếm cho "%s"', 'tube-theme'),
        esc_html($tube_theme_query_display)
    );
    ?>
</h1>

<?php if ([] === $tube_theme_items && 1 === $tube_theme_page) : ?>
    <div class="empty-state">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="11" cy="11" r="7" />
            <line x1="21" y1="21" x2="16.65" y2="16.65" />
        </svg>
        <p><?php esc_html_e('Không tìm thấy video nào phù hợp.', 'tube-theme'); ?></p>
    </div>
<?php else : ?>
    <?php
    get_template_part(
        'template-parts/video-grid',
        null,
        [
            'videos'        => $tube_theme_items,
            'empty_message' => __('Không còn video nào khác phù hợp.', 'tube-theme'),
            'page'          => $tube_theme_page,
            'total_pages'   => $tube_theme_total_pages,
            'page_url'      => static fn (int $tube_theme_target_page): string =>
                home_url('/search/' . rawurlencode($tube_theme_query) . '/')
                    . ($tube_theme_target_page > 1 ? 'page/' . $tube_theme_target_page . '/' : ''),
        ]
    );
    ?>
<?php endif; ?>

<?php
get_footer();
