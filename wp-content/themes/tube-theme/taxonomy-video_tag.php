<?php
/**
 * Tag archive (`/tag/{slug}/`). Same shape as taxonomy-video_category.php.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$tube_theme_term = get_queried_object();

if ($tube_theme_term instanceof WP_Term) {
    $tube_theme_page   = tube_theme_current_page();
    $tube_theme_result = tube_search_by_tag($tube_theme_term->term_id, $tube_theme_page);

    $tube_theme_term_link = get_term_link($tube_theme_term);
    $tube_theme_base_url  = is_string($tube_theme_term_link) ? $tube_theme_term_link : home_url('/');

    get_template_part(
        'template-parts/breadcrumbs',
        null,
        [
            'items' => [
                [
                    'name' => __('Trang chủ', 'tube-theme'),
                    'url'  => home_url('/'),
                ],
                [
                    'name' => $tube_theme_term->name,
                    'url'  => $tube_theme_base_url,
                ],
            ],
        ]
    );

    get_template_part(
        'template-parts/archive-listing',
        null,
        [
            'title'         => $tube_theme_term->name,
            'description'   => wp_strip_all_tags(term_description($tube_theme_term->term_id)),
            'result'        => $tube_theme_result,
            'page'          => $tube_theme_page,
            'page_url'      => static fn (int $tube_theme_target_page): string => $tube_theme_target_page > 1
                ? trailingslashit($tube_theme_base_url) . 'page/' . $tube_theme_target_page . '/'
                : $tube_theme_base_url,
            'empty_message' => __('Chưa có video nào với tag này.', 'tube-theme'),
        ]
    );
}

get_footer();
