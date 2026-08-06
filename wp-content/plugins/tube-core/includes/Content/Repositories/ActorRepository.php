<?php
/**
 * Data access for wp_tube_actors/wp_tube_video_actors (ActorRepositoryInterface).
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Content\Repositories;

use Tube_Core\Content\Actor;

/**
 * Data access for `wp_tube_actors`/`wp_tube_video_actors`
 * (ActorRepositoryInterface). Direct `$wpdb` access is the same
 * documented, intentional exception every dedicated-table repository in
 * this project uses (ARCHITECTURE.md §2.5/§11) — no WP_Query/
 * WP_Meta_Query equivalent exists for these tables.
 */
final class ActorRepository implements ActorRepositoryInterface
{
    /**
     * Every column a row-read query selects — never `SELECT *`.
     */
    private const COLUMNS = 'id, name, slug, bio, photo_image_id';

    /**
     * {@inheritDoc}
     *
     * @param int $actor_id The actor's row ID.
     */
    public function find(int $actor_id): ?Actor
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::COLUMNS is a fixed internal constant (never caller-supplied), not a value; every actual value is still a %i/%d-bound argument.
                'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d',
                $wpdb->prefix . 'tube_actors',
                $actor_id
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
     * @param string $slug The actor's URL slug.
     */
    public function find_by_slug(string $slug): ?Actor
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::COLUMNS is a fixed internal constant (never caller-supplied), not a value; every actual value is still a %i/%s-bound argument.
                'SELECT ' . self::COLUMNS . ' FROM %i WHERE slug = %s',
                $wpdb->prefix . 'tube_actors',
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
    public function actor_ids_for_video(int $video_id): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT actor_id FROM %i WHERE video_id = %d',
                $wpdb->prefix . 'tube_video_actors',
                $video_id
            )
        );

        return array_values(array_map(static fn (mixed $id): int => is_scalar($id) ? (int) $id : 0, (array) $ids));
    }

    /**
     * {@inheritDoc}
     *
     * @param int $actor_id The actor's row ID.
     */
    public function count_videos_for_actor(int $actor_id): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE actor_id = %d',
                $wpdb->prefix . 'tube_video_actors',
                $actor_id
            )
        );

        return null === $count ? 0 : (int) $count;
    }

    /**
     * {@inheritDoc}
     *
     * @param int   $video_id  The video post ID.
     * @param int[] $actor_ids The actor IDs this video should be assigned to.
     */
    public function replace_for_video(int $video_id, array $actor_ids): void
    {
        $actor_ids = array_values(array_unique(array_map('intval', $actor_ids)));
        $current   = $this->actor_ids_for_video($video_id);

        $to_add    = array_values(array_diff($actor_ids, $current));
        $to_remove = array_values(array_diff($current, $actor_ids));

        if ([] === $to_add && [] === $to_remove) {
            return;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_video_actors';

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
     * @param int[] $video_ids The video post IDs to add actors to.
     * @param int[] $actor_ids The actor IDs to add.
     */
    public function bulk_add(array $video_ids, array $actor_ids): int
    {
        $video_ids = array_values(array_unique(array_map('intval', $video_ids)));
        $actor_ids = array_values(array_unique(array_map('intval', $actor_ids)));

        if ([] === $video_ids || [] === $actor_ids) {
            return 0;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $inserted = $this->insert_pairs($wpdb->prefix . 'tube_video_actors', $video_ids, $actor_ids);

        $this->refresh_video_counts($actor_ids);

        return $inserted;
    }

    /**
     * {@inheritDoc}
     *
     * @param int[] $video_ids The video post IDs to remove actors from.
     * @param int[] $actor_ids The actor IDs to remove.
     */
    public function bulk_remove(array $video_ids, array $actor_ids): int
    {
        $video_ids = array_values(array_unique(array_map('intval', $video_ids)));
        $actor_ids = array_values(array_unique(array_map('intval', $actor_ids)));

        if ([] === $video_ids || [] === $actor_ids) {
            return 0;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $deleted = $this->delete_pairs($wpdb->prefix . 'tube_video_actors', $video_ids, $actor_ids);

        $this->refresh_video_counts($actor_ids);

        return $deleted;
    }

    /**
     * Insert every (video_id, actor_id) pair in the cross product of
     * `$video_ids` x `$actor_ids` in one multi-row `INSERT IGNORE`, per
     * ARCHITECTURE.md §19.8 — never a loop of single-row inserts. Pairs
     * that already exist are silently skipped (`IGNORE`), not an error.
     *
     * @param string $table     The relationship table (already prefixed).
     * @param int[]  $video_ids Non-empty list of video post IDs.
     * @param int[]  $actor_ids Non-empty list of actor IDs.
     *
     * @return int The number of rows actually inserted.
     */
    private function insert_pairs(string $table, array $video_ids, array $actor_ids): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $value_tuples = [];

        foreach ($video_ids as $video_id) {
            foreach ($actor_ids as $actor_id) {
                $value_tuples[] = sprintf('(%d, %d)', $video_id, $actor_id);
            }
        }

        // Every value here is a PHP int (cast by the public callers above),
        // never caller-supplied SQL text -- the same variable-length-VALUES
        // pattern documented in full by
        // Tube_Core\Views\Repositories\VideoViewsRepository::bulk_record().
        $sql = 'INSERT IGNORE INTO ' . $table . ' (video_id, actor_id) VALUES ' . implode(', ', $value_tuples);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); see the comment above $sql's assignment for why this isn't prepare()'d.
        $wpdb->query($sql);

        return (int) $wpdb->rows_affected;
    }

    /**
     * Delete every (video_id, actor_id) pair in the cross product of
     * `$video_ids` x `$actor_ids` in one query.
     *
     * @param string $table     The relationship table (already prefixed).
     * @param int[]  $video_ids Non-empty list of video post IDs.
     * @param int[]  $actor_ids Non-empty list of actor IDs.
     *
     * @return int The number of rows actually deleted.
     */
    private function delete_pairs(string $table, array $video_ids, array $actor_ids): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $video_placeholders = implode(', ', array_fill(0, count($video_ids), '%d'));
        $actor_placeholders = implode(', ', array_fill(0, count($actor_ids), '%d'));

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $video_placeholders/$actor_placeholders are fixed-shape strings of literal "%d" tokens, never external input; every actual value is still a %i/%d-bound argument below.
            "DELETE FROM %i WHERE video_id IN ({$video_placeholders}) AND actor_id IN ({$actor_placeholders})",
            array_merge([$table], $video_ids, $actor_ids)
        );

        if (null === $sql) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above.
        $wpdb->query($sql);

        return (int) $wpdb->rows_affected;
    }

    /**
     * Recompute `wp_tube_actors.video_count` for exactly the given actor
     * IDs from the real `wp_tube_video_actors` relationship table (a live
     * `COUNT()` per actor, applied in one query) — self-healing rather
     * than an incremental +1/-1, so it stays correct even if a row was
     * ever touched outside this repository (e.g. direct `$wpdb` test
     * seeding, per `ActorStudioIntegrationTest`'s pre-Phase-10 precedent).
     *
     * @param int[] $actor_ids Non-empty list of actor IDs to refresh.
     */
    private function refresh_video_counts(array $actor_ids): void
    {
        if ([] === $actor_ids) {
            return;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $placeholders = implode(', ', array_fill(0, count($actor_ids), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the sniff's static replacement count only sees the 2 literal %i tokens below and array_merge()'s fixed-size first argument; it can't see that $actor_ids (variable length, one %d per element via $placeholders) is concatenated in on the next line, so it undercounts. Every actual value is still a %i/%d-bound argument.
        $sql = $wpdb->prepare(
            'UPDATE %i a SET video_count = (SELECT COUNT(*) FROM %i va WHERE va.actor_id = a.id)'
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $placeholders is a fixed-shape string of literal "%d" tokens (one per element of $actor_ids), never external input; every actual value is still a %i/%d-bound argument via array_merge() below.
                . " WHERE a.id IN ({$placeholders})",
            array_merge([$wpdb->prefix . 'tube_actors', $wpdb->prefix . 'tube_video_actors'], $actor_ids)
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
     * @param int $limit  Maximum number of actors to return.
     * @param int $offset Number of actors to skip, for pagination.
     *
     * @return Actor[]
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
                $wpdb->prefix . 'tube_actors',
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
        $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'tube_actors'));

        return null === $count ? 0 : (int) $count;
    }

    /**
     * {@inheritDoc}
     *
     * @param string      $name The actor's display name.
     * @param string|null $bio  The actor's biography, if any.
     */
    public function create(string $name, ?string $bio): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $now = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->insert(
            $wpdb->prefix . 'tube_actors',
            [
                'name'       => $name,
                'slug'       => sanitize_title($name),
                'bio'        => $bio,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );

        $actor_id = (int) $wpdb->insert_id;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->update(
            $wpdb->prefix . 'tube_actors',
            ['slug' => sanitize_title($name) . '-' . $actor_id],
            ['id' => $actor_id],
            ['%s'],
            ['%d']
        );

        return $actor_id;
    }

    /**
     * Turn one raw $wpdb row into an Actor.
     *
     * Same documented wordpress-stubs gap as
     * `Tube_Search\Index\SearchIndexRepository::hydrate()` for the inline
     * `@var` narrowing below.
     *
     * @param array<string, string|null> $row One raw $wpdb row.
     */
    private static function hydrate(array $row): Actor
    {
        /** @var array{id: string, name: string, slug: string, bio: string|null, photo_image_id: string|null} $row */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        return new Actor(
            (int) $row['id'],
            $row['name'],
            $row['slug'],
            $row['bio'],
            null === $row['photo_image_id'] ? null : (int) $row['photo_image_id']
        );
    }
}
