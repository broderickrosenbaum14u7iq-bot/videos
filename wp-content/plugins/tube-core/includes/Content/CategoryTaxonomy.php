<?php
/**
 * Registers the `video_category` taxonomy.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Content;

/**
 * Registers the `video_category` taxonomy.
 *
 * Scoped only to the `video` post type — not shared with core `category`
 * per ARCHITECTURE.md §1.2. Hierarchical, matching the genre/category
 * archive pages requirement. Kept as a native WordPress taxonomy (unlike
 * actor/studio, which are dedicated tables per ARCHITECTURE.md §14) since
 * its cardinality profile — few terms per video, a moderate total term
 * count, real benefit from hierarchy — does not create the write-side
 * scaling problems that ruled out taxonomies for actor/studio.
 */
final class CategoryTaxonomy
{
    /**
     * The taxonomy slug.
     */
    public const TAXONOMY = 'video_category';

    /**
     * Register the `video_category` taxonomy against the `video` post type.
     *
     * Safe to call directly (e.g. during plugin activation) as well as
     * from an `init` callback.
     */
    public function register_taxonomy(): void
    {
        register_taxonomy(self::TAXONOMY, [VideoPostType::POST_TYPE], $this->args());
    }

    /**
     * Build the registration arguments for the `video_category` taxonomy.
     *
     * @return array{
     *     labels: array<string, string>,
     *     description: string,
     *     hierarchical: bool,
     *     public: bool,
     *     publicly_queryable: bool,
     *     show_ui: bool,
     *     show_admin_column: bool,
     *     show_in_nav_menus: bool,
     *     show_in_rest: bool,
     *     rest_base: string,
     *     query_var: bool,
     *     rewrite: array{slug: string, with_front: bool, hierarchical: bool},
     * }
     */
    private function args(): array
    {
        return [
            'labels'             => $this->labels(),
            'description'        => __('Genre/category classification for videos.', 'tube-core'),
            'hierarchical'       => true,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_admin_column'  => true,
            'show_in_nav_menus'  => true,
            'show_in_rest'       => true,
            'rest_base'          => 'video-categories',
            'query_var'          => true,
            'rewrite'            => [
                'slug'         => 'category',
                'with_front'   => false,
                'hierarchical' => true,
            ],
        ];
    }

    /**
     * Build the admin-facing labels for the `video_category` taxonomy.
     *
     * @return array<string, string>
     */
    private function labels(): array
    {
        return [
            'name'                       => _x('Categories', 'taxonomy general name', 'tube-core'),
            'singular_name'              => _x('Category', 'taxonomy singular name', 'tube-core'),
            'menu_name'                  => __('Categories', 'tube-core'),
            'all_items'                  => __('All Categories', 'tube-core'),
            'parent_item'                => __('Parent Category', 'tube-core'),
            'parent_item_colon'          => __('Parent Category:', 'tube-core'),
            'new_item_name'              => __('New Category Name', 'tube-core'),
            'add_new_item'               => __('Add New Category', 'tube-core'),
            'edit_item'                  => __('Edit Category', 'tube-core'),
            'update_item'                => __('Update Category', 'tube-core'),
            'view_item'                  => __('View Category', 'tube-core'),
            'separate_items_with_commas' => __('Separate categories with commas', 'tube-core'),
            'add_or_remove_items'        => __('Add or remove categories', 'tube-core'),
            'choose_from_most_used'      => __('Choose from the most used categories', 'tube-core'),
            'popular_items'              => __('Popular Categories', 'tube-core'),
            'search_items'               => __('Search Categories', 'tube-core'),
            'not_found'                  => __('No categories found.', 'tube-core'),
            'no_terms'                   => __('No categories', 'tube-core'),
            'items_list_navigation'      => __('Categories list navigation', 'tube-core'),
            'items_list'                 => __('Categories list', 'tube-core'),
            'back_to_items'              => __('Back to Categories', 'tube-core'),
        ];
    }
}
