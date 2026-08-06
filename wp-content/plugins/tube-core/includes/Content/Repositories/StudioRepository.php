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
     * {@inheritDoc}
     *
     * @param int   $video_id   The video post ID.
     * @param int[] $studio_ids The studio IDs this video should be assigned to.
     */
    public function replace_for_video(int $video_id, array $studio_ids): void
    {
        $studio_ids = array_values(array_unique(array_map('intval', $studio_ids)));
        $current    = $this->studio_ids_for_video($video_id);

        $to_add    = array_values(array_diff($studio_ids, $current));
        $to_remove = array_values(array_diff($current, $studio_ids));

        if ([] === $to_add && [] === $to_remove) {
            return;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_video_studios';

        if ([] !== $to_remove) {
            $this->delete_pairs($table, [$video_id], $to_remove);
        }

        if ([] !== $to_add) {
            $this->insert_pairs($table, [$video_id], $to_add);
        }

        $this->refresh_video_counts(array_values(array_unique(array_merge($to_add, $to_remove))));
    }

    /**
     * {@inheritDoc}
     *
     * @param int[] $video_ids  The video post IDs to add studios to.
     * @param int[] $studio_ids The studio IDs to add.
     */
    public function bulk_add(array $video_ids, array $studio_ids): int
    {
        $video_ids  = array_values(array_unique(array_map('intval', $video_ids)));
        $studio_ids = array_values(array_unique(array_map('intval', $studio_ids)));

        if ([] === $video_ids || [] === $studio_ids) {
            return 0;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $inserted = $this->insert_pairs($wpdb->prefix . 'tube_video_studios', $video_ids, $studio_ids);

        $this->refresh_video_counts($studio_ids);

        return $inserted;
    }

    /**
     * {@inheritDoc}
     *
     * @param int[] $video_ids  The video post IDs to remove studios from.
     * @param int[] $studio_ids The studio IDs to remove.
     */
    public function bulk_remove(array $video_ids, array $studio_ids): int
    {
        $video_ids  = array_values(array_unique(array_map('intval', $video_ids)));
        $studio_ids = array_values(array_unique(array_map('intval', $studio_ids)));

        if ([] === $video_ids || [] === $studio_ids) {
            return 0;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $deleted = $this->delete_pairs($wpdb->prefix . 'tube_video_studios', $video_ids, $studio_ids);

        $this->refresh_video_counts($studio_ids);

        return $deleted;
    }

    /**
     * Insert every (video_id, studio_id) pair in the cross product of
     * `$video_ids` x `$studio_ids` in one multi-row `INSERT IGNORE`, per
     * ARCHITECTURE.md §19.8. Same shape as
     * `ActorRepository::insert_pairs()`; see its docblock.
     *
     * @param string $table      The relationship table (already prefixed).
     * @param int[]  $video_ids  Non-empty list of video post IDs.
     * @param int[]  $studio_ids Non-empty list of studio IDs.
     *
     * @return int The number of rows actually inserted.
     */
    private function insert_pairs(string $table, array $video_ids, array $studio_ids): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $value_tuples = [];

        foreach ($video_ids as $video_id) {
            foreach ($studio_ids as $studio_id) {
                $value_tuples[] = sprintf('(%d, %d)', $video_id, $studio_id);
            }
        }

        // Every value here is a PHP int (cast by the public callers above),
        // never caller-supplied SQL text -- the same variable-length-VALUES
        // pattern documented in full by
        // Tube_Core\Views\Repositories\VideoViewsRepository::bulk_record().
        $sql = 'INSERT IGNORE INTO ' . $table . ' (video_id, studio_id) VALUES ' . implode(', ', $value_tuples);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); see the comment above $sql's assignment for why this isn't prepare()'d.
        $wpdb->query($sql);

        return (int) $wpdb->rows_affected;
    }

    /**
     * Delete every (video_id, studio_id) pair in the cross product of
     * `$video_ids` x `$studio_ids` in one query.
     *
     * @param string $table      The relationship table (already prefixed).
     * @param int[]  $video_ids  Non-empty list of video post IDs.
     * @param int[]  $studio_ids Non-empty list of studio IDs.
     *
     * @return int The number of rows actually deleted.
     */
    private function delete_pairs(string $table, array $video_ids, array $studio_ids): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $video_placeholders  = implode(', ', array_fill(0, count($video_ids), '%d'));
        $studio_placeholders = implode(', ', array_fill(0, count($studio_ids), '%d'));

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $video_placeholders/$studio_placeholders are fixed-shape strings of literal "%d" tokens, never external input; every actual value is still a %i/%d-bound argument below.
            "DELETE FROM %i WHERE video_id IN ({$video_placeholders}) AND studio_id IN ({$studio_placeholders})",
            array_merge([$table], $video_ids, $studio_ids)
        );

        if (null === $sql) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above.
        $wpdb->query($sql);

        return (int) $wpdb->rows_affected;
    }

    /**
     * Recompute `wp_tube_studios.video_count` for exactly the given studio
     * IDs. Same self-healing shape as `ActorRepository::refresh_video_counts()`; see its docblock.
     *
     * @param int[] $studio_ids Non-empty list of studio IDs to refresh.
     */
    private function refresh_video_counts(array $studio_ids): void
    {
        if ([] === $studio_ids) {
            return;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $placeholders = implode(', ', array_fill(0, count($studio_ids), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the sniff's static replacement count only sees the 2 literal %i tokens below and array_merge()'s fixed-size first argument; it can't see that $studio_ids (variable length, one %d per element via $placeholders) is concatenated in on the next line, so it undercounts. Every actual value is still a %i/%d-bound argument.
        $sql = $wpdb->prepare(
            'UPDATE %i s SET video_count = (SELECT COUNT(*) FROM %i vs WHERE vs.studio_id = s.id)'
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $placeholders is a fixed-shape string of literal "%d" tokens (one per element of $studio_ids), never external input; every actual value is still a %i/%d-bound argument via array_merge() below.
                . " WHERE s.id IN ({$placeholders})",
            array_merge([$wpdb->prefix . 'tube_studios', $wpdb->prefix . 'tube_video_studios'], $studio_ids)
        );

        if (null === $sql) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above.
        $wpdb->query($sql);
    }

    /**
     * {@inheritDoc}
     *
     * @param int $limit  Maximum number of studios to return.
     * @param int $offset Number of studios to skip, for pagination.
     *
     * @return Studio[]
     */
    public function list_all(int $limit, int $offset): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::COLUMNS is a fixed internal constant (never caller-supplied); every actual value is still a %i/%d-bound argument.
                'SELECT ' . self::COLUMNS . ' FROM %i ORDER BY name ASC LIMIT %d OFFSET %d',
                $wpdb->prefix . 'tube_studios',
                $limit,
                $offset
            ),
            ARRAY_A
        );

        /** @var array<int, array<string, string|null>> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $rows = (array) $rows;

        return array_map([self::class, 'hydrate'], $rows);
    }

    /**
     * {@inheritDoc}
     */
    public function count_all(): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'tube_studios'));

        return null === $count ? 0 : (int) $count;
    }

    /**
     * {@inheritDoc}
     *
     * @param string      $name        The studio's display name.
     * @param string|null $description The studio's description, if any.
     * @param string|null $website_url The studio's external website, if any.
     */
    public function create(string $name, ?string $description, ?string $website_url): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $now = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->insert(
            $wpdb->prefix . 'tube_studios',
            [
                'name'        => $name,
                'slug'        => sanitize_title($name),
                'description' => $description,
                'website_url' => $website_url,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        $studio_id = (int) $wpdb->insert_id;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->update(
            $wpdb->prefix . 'tube_studios',
            ['slug' => sanitize_title($name) . '-' . $studio_id],
            ['id' => $studio_id],
            ['%s'],
            ['%d']
        );

        return $studio_id;
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
