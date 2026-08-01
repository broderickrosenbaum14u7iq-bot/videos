<?php
/**
 * Registers the `video` Custom Post Type.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Content;

/**
 * Registers the `video` Custom Post Type.
 *
 * One video is one `video` post, per ARCHITECTURE.md §1.1. Native `post`
 * is never used for video content. This class deliberately omits
 * `editor` support: a video item is structured data (title, an excerpt
 * used as the description, and the Cloudflare Stream UID plus related
 * fields in wp_tube_video_metadata, see Migration001CreateVideoMetadataTable),
 * not freeform post content.
 */
final class VideoPostType
{
    /**
     * The post type slug.
     */
    public const POST_TYPE = 'video';

    /**
     * Register the `video` post type with WordPress.
     *
     * Safe to call directly (e.g. during plugin activation, before the
     * `init` hook has fired) as well as from an `init` callback — it has
     * no side effects beyond the `register_post_type()` call itself.
     */
    public function register_post_type(): void
    {
        register_post_type(self::POST_TYPE, $this->args());
    }

    /**
     * Build the registration arguments for the `video` post type.
     *
     * @return array<string, mixed>
     */
    private function args(): array
    {
        return [
            'labels'             => $this->labels(),
            'description'        => __('A single video, hosted on Cloudflare Stream.', 'tube-core'),
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => true,
            'show_in_admin_bar'  => true,
            'show_in_rest'       => true,
            'rest_base'          => 'videos',
            'has_archive'        => true,
            'supports'           => ['title', 'thumbnail', 'excerpt', 'custom-fields', 'author'],
            'rewrite'            => [
                'slug'       => 'watch',
                'with_front' => false,
            ],
            'menu_icon'          => 'dashicons-video-alt2',
            'menu_position'      => 5,
            'capability_type'    => 'post',
            'map_meta_cap'       => true,
            'hierarchical'       => false,
            'query_var'          => true,
            'can_export'         => true,
            'delete_with_user'   => false,
        ];
    }

    /**
     * Build the admin-facing labels for the `video` post type.
     *
     * @return array<string, string>
     */
    private function labels(): array
    {
        return [
            'name'                  => _x('Videos', 'post type general name', 'tube-core'),
            'singular_name'         => _x('Video', 'post type singular name', 'tube-core'),
            'menu_name'             => _x('Videos', 'admin menu', 'tube-core'),
            'name_admin_bar'        => _x('Video', 'add new on admin bar', 'tube-core'),
            'add_new'               => _x('Add New', 'video', 'tube-core'),
            'add_new_item'          => __('Add New Video', 'tube-core'),
            'new_item'              => __('New Video', 'tube-core'),
            'edit_item'             => __('Edit Video', 'tube-core'),
            'view_item'             => __('View Video', 'tube-core'),
            'view_items'            => __('View Videos', 'tube-core'),
            'all_items'             => __('All Videos', 'tube-core'),
            'search_items'          => __('Search Videos', 'tube-core'),
            'not_found'             => __('No videos found.', 'tube-core'),
            'not_found_in_trash'    => __('No videos found in Trash.', 'tube-core'),
            'archives'              => __('Video Archives', 'tube-core'),
            'attributes'            => __('Video Attributes', 'tube-core'),
            'insert_into_item'      => __('Insert into video', 'tube-core'),
            'uploaded_to_this_item' => __('Uploaded to this video', 'tube-core'),
            'featured_image'        => __('Poster Image', 'tube-core'),
            'set_featured_image'    => __('Set poster image', 'tube-core'),
            'remove_featured_image' => __('Remove poster image', 'tube-core'),
            'use_featured_image'    => __('Use as poster image', 'tube-core'),
        ];
    }
}
