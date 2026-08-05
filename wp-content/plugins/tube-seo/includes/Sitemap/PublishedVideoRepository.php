<?php
/**
 * Data access for the set of currently-published videos, for sitemap generation.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Sitemap;

use Tube_Core\Content\VideoPostType;

/**
 * Data access for the set of currently-published videos, read directly
 * from `wp_posts` — WordPress core's own table, not a "dedicated custom
 * table" any plugin owns (`DEVELOPMENT_RULES.md` §6.8's plugin-boundary
 * rule doesn't apply here the way it does to `wp_tube_*` tables; every
 * plugin in this project already reads `wp_posts` via native WordPress
 * APIs — `get_post()`, `get_the_terms()`, `get_the_excerpt()`, etc.).
 *
 * A raw, minimal `$wpdb` read (not `WP_Query`/`get_posts()`) specifically
 * to select only the three columns sitemap generation needs, for every
 * published video in one query — avoiding both "unnecessary `WP_Query`"
 * (which has no way to select a column subset) and full `WP_Post`/
 * postmeta hydration for up to 10,000 rows, per this phase's explicit
 * "efficient generation for the expected project scale" requirement.
 */
final class PublishedVideoRepository
{
    /**
     * The video post IDs and dates for every currently-published video, ordered by post ID.
     *
     * @return list<array{video_id: int, post_date_gmt: string, post_modified_gmt: string}>
     */
    public function published_videos(): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- core wp_posts table, no WP_Query equivalent selects a column subset. See this class's own docblock.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT ID, post_date_gmt, post_modified_gmt FROM %i'
                    . ' WHERE post_type = %s AND post_status = %s ORDER BY ID ASC',
                $wpdb->posts,
                VideoPostType::POST_TYPE,
                'publish'
            ),
            ARRAY_A
        );

        /** @var array<int, array{ID: string, post_date_gmt: string, post_modified_gmt: string}> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $rows = (array) $rows;

        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'video_id'          => (int) $row['ID'],
                'post_date_gmt'     => $row['post_date_gmt'],
                'post_modified_gmt' => $row['post_modified_gmt'],
            ];
        }

        return $result;
    }

    /**
     * A cheap summary of the current published-video set — the count and
     * the most recent modification timestamp among them — used to detect
     * whether anything has changed since the last sitemap generation
     * without fetching every row. Both the count and the max-modified
     * timestamp are tracked (not just one): an unpublish only changes the
     * count (the video drops out of this `WHERE post_status = 'publish'`
     * set, but that in itself doesn't change `MAX(post_modified_gmt)`
     * among what remains), while a title/content edit only changes the
     * max-modified timestamp.
     *
     * @return array{count: int, max_modified_gmt: string|null}
     */
    public function aggregate_state(): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- core wp_posts table, no WP_Query equivalent for an aggregate query. See this class's own docblock.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS video_count, MAX(post_modified_gmt) AS max_modified_gmt FROM %i'
                    . ' WHERE post_type = %s AND post_status = %s',
                $wpdb->posts,
                VideoPostType::POST_TYPE,
                'publish'
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return [
                'count'            => 0,
                'max_modified_gmt' => null,
            ];
        }

        /** @var array{video_count: string, max_modified_gmt: string|null} $row */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        return [
            'count'            => (int) $row['video_count'],
            'max_modified_gmt' => $row['max_modified_gmt'],
        ];
    }
}
