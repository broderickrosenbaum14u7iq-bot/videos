<?php
/**
 * Registers the `video_tag` taxonomy.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Content;

/**
 * Registers the `video_tag` taxonomy.
 *
 * Scoped only to the `video` post type — not shared with core `post_tag`
 * per ARCHITECTURE.md §1.2. Non-hierarchical freeform tagging.
 */
final class TagTaxonomy
{
    /**
     * The taxonomy slug.
     */
    public const TAXONOMY = 'video_tag';

    /**
     * Register the `video_tag` taxonomy against the `video` post type.
     *
     * Safe to call directly (e.g. during plugin activation) as well as
     * from an `init` callback.
     */
    public function register_taxonomy(): void
    {
        register_taxonomy(self::TAXONOMY, [VideoPostType::POST_TYPE], $this->args());
    }

    /**
     * Build the registration arguments for the `video_tag` taxonomy.
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
     *     rewrite: array{slug: string, with_front: bool},
     * }
     */
    private function args(): array
    {
        return [
            'labels'             => $this->labels(),
            'description'        => __('Freeform descriptive tags for videos.', 'tube-core'),
            'hierarchical'       => false,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_admin_column'  => true,
            'show_in_nav_menus'  => true,
            'show_in_rest'       => true,
            'rest_base'          => 'video-tags',
            'query_var'          => true,
            'rewrite'            => [
                'slug'       => 'tag',
                'with_front' => false,
            ],
        ];
    }

    /**
     * Build the admin-facing labels for the `video_tag` taxonomy.
     *
     * @return array<string, string>
     */
    private function labels(): array
    {
        return [
            'name'                       => _x('Tags', 'taxonomy general name', 'tube-core'),
            'singular_name'              => _x('Tag', 'taxonomy singular name', 'tube-core'),
            'menu_name'                  => __('Tags', 'tube-core'),
            'all_items'                  => __('All Tags', 'tube-core'),
            'new_item_name'              => __('New Tag Name', 'tube-core'),
            'add_new_item'               => __('Add New Tag', 'tube-core'),
            'edit_item'                  => __('Edit Tag', 'tube-core'),
            'update_item'                => __('Update Tag', 'tube-core'),
            'view_item'                  => __('View Tag', 'tube-core'),
            'separate_items_with_commas' => __('Separate tags with commas', 'tube-core'),
            'add_or_remove_items'        => __('Add or remove tags', 'tube-core'),
            'choose_from_most_used'      => __('Choose from the most used tags', 'tube-core'),
            'popular_items'              => __('Popular Tags', 'tube-core'),
            'search_items'               => __('Search Tags', 'tube-core'),
            'not_found'                  => __('No tags found.', 'tube-core'),
            'no_terms'                   => __('No tags', 'tube-core'),
            'items_list_navigation'      => __('Tags list navigation', 'tube-core'),
            'items_list'                 => __('Tags list', 'tube-core'),
            'back_to_items'              => __('Back to Tags', 'tube-core'),
        ];
    }
}
