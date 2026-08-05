<?php
/**
 * Data access for wp_tube_studios/wp_tube_video_studios (StudioRepositoryInterface).
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Content\Repositories;

use Tube_Core\Content\Studio;

/**
 * Data access for `wp_tube_studios`/`wp_tube_video_studios`
 * (StudioRepositoryInterface). Direct `$wpdb` access is the same
 * documented, intentional exception every dedicated-table repository in
 * this project uses (ARCHITECTURE.md §2.5/§11).
 */
final class StudioRepository implements StudioRepositoryInterface
{
    /**
     * Every column a row-read query selects — never `SELECT *`.
     */
    private const COLUMNS = 'id, name, slug, description, logo_image_id, website_url, parent_id';

    /**
     * {@inheritDoc}
     *
     * @param int $studio_id The studio's row ID.
     */
    public function find(int $studio_id): ?Studio
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::COLUMNS is a fixed internal constant (never caller-supplied), not a value; every actual value is still a %i/%d-bound argument.
                'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d',
                $wpdb->prefix . 'tube_studios',
                $studio_id
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return null;
        }

        // Same documented wordpress-stubs gap as
        // Tube_Search\Index\SearchIndexRepository::find().
        /** @var array<string, string|null> $row */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        return self::hydrate($row);
    }

    /**
     * {@inheritDoc}
     *
     * @param string $slug The studio's URL slug.
     */
    public function find_by_slug(string $slug): ?Studio
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::COLUMNS is a fixed internal constant (never caller-supplied), not a value; every actual value is still a %i/%s-bound argument.
                'SELECT ' . self::COLUMNS . ' FROM %i WHERE slug = %s',
                $wpdb->prefix . 'tube_studios',
                $slug
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return null;
        }

        // Same documented wordpress-stubs gap as
        // Tube_Search\Index\SearchIndexRepository::find().
        /** @var array<string, string|null> $row */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        return self::hydrate($row);
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video post ID.
     *
     * @return int[]
     */
    public function studio_ids_for_video(int $video_id): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT studio_id FROM %i WHERE video_id = %d',
                $wpdb->prefix . 'tube_video_studios',
                $video_id
            )
        );

        return array_values(array_map(static fn (mixed $id): int => is_scalar($id) ? (int) $id : 0, (array) $ids));
    }

    /**
     * {@inheritDoc}
     *
     * @param int $studio_id The studio's row ID.
     */
    public function count_videos_for_studio(int $studio_id): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE studio_id = %d',
                $wpdb->prefix . 'tube_video_studios',
                $studio_id
            )
        );

        return null === $count ? 0 : (int) $count;
    }

    /**
     * Turn one raw $wpdb row into a Studio.
     *
     * Same documented wordpress-stubs gap as
     * `Tube_Search\Index\SearchIndexRepository::hydrate()` for the inline
     * `@var` narrowing below.
     *
     * @param array<string, string|null> $row One raw $wpdb row.
     */
    private static function hydrate(array $row): Studio
    {
        /** @var array{id: string, name: string, slug: string, description: string|null, logo_image_id: string|null, website_url: string|null, parent_id: string|null} $row */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        return new Studio(
            (int) $row['id'],
            $row['name'],
            $row['slug'],
            $row['description'],
            null === $row['logo_image_id'] ? null : (int) $row['logo_image_id'],
            $row['website_url'],
            null === $row['parent_id'] ? null : (int) $row['parent_id']
        );
    }
}
