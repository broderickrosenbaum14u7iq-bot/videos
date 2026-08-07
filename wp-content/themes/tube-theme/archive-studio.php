<?php
/**
 * Studio archive (`/studio/{slug}/`). Same shape as archive-actor.php.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$tube_theme_studio = tube_core_get_current_studio();

if (null !== $tube_theme_studio) {
    $tube_theme_page   = tube_theme_current_page();
    $tube_theme_result = tube_search_by_studio($tube_theme_studio->id, $tube_theme_page);

    $tube_theme_base_url = home_url('/studio/' . $tube_theme_studio->slug . '/');

    get_template_part(
        'template-parts/breadcrumbs',
        null,
        [
            'items' => [
                [
                    'name' => __('Home', 'tube-theme'),
                    'url'  => home_url('/'),
                ],
                [
                    'name' => $tube_theme_studio->name,
                    'url'  => $tube_theme_base_url,
                ],
            ],
        ]
    );

    get_template_part(
        'template-parts/profile-header',
        null,
        [
            'name'     => $tube_theme_studio->name,
            'bio'      => $tube_theme_studio->description,
            'image_id' => $tube_theme_studio->logo_image_id,
        ]
    );

    get_template_part(
        'template-parts/archive-listing',
        null,
        [
            'title'         => __('Videos', 'tube-theme'),
            'description'   => '',
            'heading_tag'   => 'h2',
            'result'        => $tube_theme_result,
            'page'          => $tube_theme_page,
            'page_url'      => static fn (int $tube_theme_target_page): string => $tube_theme_target_page > 1
                ? trailingslashit($tube_theme_base_url) . 'page/' . $tube_theme_target_page . '/'
                : $tube_theme_base_url,
            'empty_message' => __('No videos found for this studio yet.', 'tube-theme'),
        ]
    );
}

get_footer();
