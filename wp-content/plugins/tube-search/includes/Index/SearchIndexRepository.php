<?php
/**
 * Data access for wp_tube_search_index (SearchIndexRepositoryInterface, DiscoveryRepositoryInterface).
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Index;

use RuntimeException;

/**
 * Data access for `wp_tube_search_index`, implementing both the write
 * side (`SearchIndexRepositoryInterface`) and the read side
 * (`DiscoveryRepositoryInterface`) — one table, one `$wpdb` connection.
 *
 * Direct `$wpdb` access is the same documented, intentional exception
 * every dedicated-table repository in this project uses (ARCHITECTURE.md
 * §2.5/§11). Every query lists its columns explicitly (no `SELECT *`)
 * and loads only what `SearchIndexRow` actually carries.
 *
 * `find_by_ids()`/`find_random()` are `ORDER BY views_total DESC`/
 * `ORDER BY RAND()` full-but-bounded scans of this table for a
 * `JSON_CONTAINS()`/random match — `category_ids`/`tag_ids`/
 * `actor_ids`/`studio_ids` are plain `VARCHAR` columns storing JSON
 * text (§2.6's own frozen schema, not a real MySQL `JSON` column type),
 * so a B-tree index can't cover a "does this JSON array contain X"
 * predicate without generated/virtual indexed columns — not part of the
 * frozen schema, and would need an ADR to add. At this phase's explicit
 * real-scale target (3,000–10,000 videos), a full scan of this table is
 * fast in practice; the same "measured against the actual target scale,
 * not assumed" reasoning `Migration005CreateVideoViewsTable` (tube-core,
 * Phase 4) already applied to skipping MySQL partitioning.
 */
final class SearchIndexRepository implements SearchIndexRepositoryInterface, DiscoveryRepositoryInterface
{
    /**
     * Every column a read query selects — never `SELECT *`.
     */
    private const COLUMNS = 'video_id, title, description, category_ids, tag_ids, actor_ids, studio_ids,'
        . ' duration_seconds, views_total, published_at';

    /**
     * {@inheritDoc}
     *
     * @param int         $video_id         The video post ID.
     * @param string      $title            The video's title.
     * @param string|null $description      The video's description/excerpt, if any.
     * @param int[]       $category_ids     `video_category` term IDs.
     * @param int[]       $tag_ids          `video_tag` term IDs.
     * @param int[]       $actor_ids        Actor IDs.
     * @param int[]       $studio_ids       Studio IDs.
     * @param int|null    $duration_seconds The video's duration, if known.
     * @param int         $views_total      The video's current all-time view count.
     * @param string|null $published_at     MySQL `DATETIME` string, or null if never published.
     *
     * @throws RuntimeException If the query template is malformed (a bug in this method, not in any argument).
     */
    public function upsert(
        int $video_id,
        string $title,
        ?string $description,
        array $category_ids,
        array $tag_ids,
        array $actor_ids,
        array $studio_ids,
        ?int $duration_seconds,
        int $views_total,
        ?string $published_at
    ): void {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $now = current_time('mysql', true);

        $sql = $wpdb->prepare(
            'INSERT INTO %i (video_id, title, description, category_ids, tag_ids, actor_ids, studio_ids,'
                . ' duration_seconds, views_total, published_at, indexed_at)'
                . ' VALUES (%d, %s, %s, %s, %s, %s, %s, %d, %d, %s, %s)'
                . ' ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description),'
                . ' category_ids = VALUES(category_ids), tag_ids = VALUES(tag_ids),'
                . ' actor_ids = VALUES(actor_ids), studio_ids = VALUES(studio_ids),'
                . ' duration_seconds = VALUES(duration_seconds), views_total = VALUES(views_total),'
                . ' published_at = VALUES(published_at), indexed_at = VALUES(indexed_at)',
            $wpdb->prefix . 'tube_search_index',
            $video_id,
            $title,
            $description,
            self::encode_ids($category_ids),
            self::encode_ids($tag_ids),
            self::encode_ids($actor_ids),
            self::encode_ids($studio_ids),
            $duration_seconds,
            $views_total,
            $published_at,
            $now
        );

        if (null === $sql) {
            throw new RuntimeException('wpdb::prepare() returned null for the upsert() query in ' . self::class . '.');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above.
        $wpdb->query($sql);
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id    The video post ID.
     * @param int $views_total The video's current all-time view count.
     */
    public function update_views_total(int $video_id, int $views_total): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->update(
            $wpdb->prefix . 'tube_search_index',
            [
                'views_total' => $views_total,
                'indexed_at'  => current_time('mysql', true),
            ],
            ['video_id' => $video_id],
            ['%d', '%s'],
            ['%d']
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id         The video post ID.
     * @param int $duration_seconds The video's now-known duration.
     */
    public function update_duration(int $video_id, int $duration_seconds): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->update(
            $wpdb->prefix . 'tube_search_index',
            [
                'duration_seconds' => $duration_seconds,
                'indexed_at'       => current_time('mysql', true),
            ],
            ['video_id' => $video_id],
            ['%d', '%s'],
            ['%d']
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video post ID.
     */
    public function delete(int $video_id): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->delete($wpdb->prefix . 'tube_search_index', ['video_id' => $video_id], ['%d']);
    }

    /**
     * {@inheritDoc}
     *
     * @return int[]
     */
    public function all_video_ids(): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $ids = $wpdb->get_col(
            $wpdb->prepare('SELECT video_id FROM %i', $wpdb->prefix . 'tube_search_index')
        );

        return array_values(array_map(static fn (mixed $id): int => is_scalar($id) ? (int) $id : 0, (array) $ids));
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video post ID.
     */
    public function find(int $video_id): ?SearchIndexRow
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::COLUMNS is a fixed internal constant (never caller-supplied), not a value; every actual value is still a %i/%d-bound argument.
                'SELECT ' . self::COLUMNS . ' FROM %i WHERE video_id = %d',
                $wpdb->prefix . 'tube_search_index',
                $video_id
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return null;
        }

        // Same documented wordpress-stubs gap as VideoViewsRepository::window_sums().
        /** @var array<string, string|null> $row */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        return self::hydrate($row);
    }

    /**
     * {@inheritDoc}
     *
     * @param int[] $video_ids The video IDs to fetch.
     *
     * @return list<SearchIndexRow>
     *
     * @throws RuntimeException If a query template is malformed (a bug in this method, not in any argument).
     */
    public function find_many(array $video_ids): array
    {
        if ([] === $video_ids) {
            return [];
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $placeholders = implode(', ', array_fill(0, count($video_ids), '%d'));

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- self::COLUMNS is a fixed internal constant; $placeholders is a fixed-shape string of literal "%d" tokens (one per element of $video_ids); neither is ever external input, and every actual value is still a %d-bound argument below.
            'SELECT ' . self::COLUMNS . " FROM %i WHERE video_id IN ({$placeholders})",
            array_merge([$wpdb->prefix . 'tube_search_index'], $video_ids)
        );

        if (null === $sql) {
            throw new RuntimeException(
                'wpdb::prepare() returned null for the find_many() query in ' . self::class . '.'
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above.
        $rows = $wpdb->get_results($sql, ARRAY_A);

        // Same documented wordpress-stubs gap as VideoViewsRepository::window_sums().
        /** @var array<int, array<string, string|null>> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $rows = (array) $rows;

        return self::hydrate_all($rows);
    }

    /**
     * {@inheritDoc}
     *
     * @param CandidateColumn $column            Which JSON-array column to match against.
     * @param int[]           $ids               Candidate IDs.
     * @param int[]           $exclude_video_ids Video IDs to never return.
     * @param int             $limit             Maximum number of rows to return.
     *
     * @return list<SearchIndexRow>
     *
     * @throws RuntimeException If a query template is malformed (a bug in this method, not in any argument).
     */
    public function find_by_ids(CandidateColumn $column, array $ids, array $exclude_video_ids, int $limit): array
    {
        if ([] === $ids) {
            return [];
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $column_name = $column->value;

        $contains_clause = implode(
            ' OR ',
            array_fill(0, count($ids), "JSON_CONTAINS({$column_name}, %s)")
        );

        $sql_template = 'SELECT ' . self::COLUMNS . " FROM %i WHERE ({$contains_clause})";
        $args         = array_merge(
            [$wpdb->prefix . 'tube_search_index'],
            array_map(static fn (int $id): string => (string) $id, $ids)
        );

        if ([] !== $exclude_video_ids) {
            $exclude_placeholders = implode(', ', array_fill(0, count($exclude_video_ids), '%d'));
            $sql_template        .= " AND video_id NOT IN ({$exclude_placeholders})";
            $args                 = array_merge($args, $exclude_video_ids);
        }

        $sql_template .= ' ORDER BY views_total DESC LIMIT %d';
        $args[]        = $limit;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $sql_template is built from self::COLUMNS (a fixed internal constant) plus $column_name (a closed CandidateColumn enum value) and fixed-shape strings of literal "%s"/"%d" tokens (one per element of $ids/$exclude_video_ids); none of this is ever external input, and every actual value is still a bound argument in $args.
        $sql = $wpdb->prepare($sql_template, $args);

        if (null === $sql) {
            throw new RuntimeException(
                'wpdb::prepare() returned null for the find_by_ids() query in ' . self::class . '.'
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above.
        $rows = $wpdb->get_results($sql, ARRAY_A);

        // Same documented wordpress-stubs gap as VideoViewsRepository::window_sums().
        /** @var array<int, array<string, string|null>> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $rows = (array) $rows;

        return self::hydrate_all($rows);
    }

    /**
     * {@inheritDoc}
     *
     * @param int[] $exclude_video_ids Video IDs to never return.
     * @param int   $limit             Maximum number of rows to return.
     *
     * @return list<SearchIndexRow>
     *
     * @throws RuntimeException If a query template is malformed (a bug in this method, not in any argument).
     */
    public function find_random(array $exclude_video_ids, int $limit): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $sql_template = 'SELECT ' . self::COLUMNS . ' FROM %i';
        $args         = [$wpdb->prefix . 'tube_search_index'];

        if ([] !== $exclude_video_ids) {
            $exclude_placeholders = implode(', ', array_fill(0, count($exclude_video_ids), '%d'));
            $sql_template        .= " WHERE video_id NOT IN ({$exclude_placeholders})";
            $args                 = array_merge($args, $exclude_video_ids);
        }

        $sql_template .= ' ORDER BY RAND() LIMIT %d';
        $args[]        = $limit;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql_template is built from self::COLUMNS (a fixed internal constant), a fixed-shape string of literal "%d" tokens (one per element of $exclude_video_ids), and the literal "ORDER BY RAND() LIMIT %d" -- none of this is ever external input, and every actual value is still a bound argument in $args; see this class's own docblock for why the RAND() full scan itself is the accepted approach at this phase's real-scale target.
        $sql = $wpdb->prepare($sql_template, $args);

        if (null === $sql) {
            throw new RuntimeException(
                'wpdb::prepare() returned null for the find_random() query in ' . self::class . '.'
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above.
        $rows = $wpdb->get_results($sql, ARRAY_A);

        // Same documented wordpress-stubs gap as VideoViewsRepository::window_sums().
        /** @var array<int, array<string, string|null>> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $rows = (array) $rows;

        return self::hydrate_all($rows);
    }

    /**
     * {@inheritDoc}
     *
     * @param int $limit Maximum number of rows to return.
     *
     * @return list<SearchIndexRow>
     */
    public function recently_added(int $limit): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::COLUMNS is a fixed internal constant (never caller-supplied), not a value; every actual value is still a %i/%d-bound argument.
                'SELECT ' . self::COLUMNS . ' FROM %i WHERE published_at IS NOT NULL'
                    . ' ORDER BY published_at DESC LIMIT %d',
                $wpdb->prefix . 'tube_search_index',
                $limit
            ),
            ARRAY_A
        );

        // Same documented wordpress-stubs gap as VideoViewsRepository::window_sums().
        /** @var array<int, array<string, string|null>> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $rows = (array) $rows;

        return self::hydrate_all($rows);
    }

    /**
     * {@inheritDoc}
     *
     * MySQL `FULLTEXT`'s default InnoDB `ft_min_word_len` (4 characters)
     * silently ignores shorter query words entirely — a server-level
     * setting, not something this method works around; a 1–3 character
     * query term simply never matches, which is standard, documented
     * MySQL `FULLTEXT` behavior, not a bug in this query.
     *
     * @param string $query  The raw search query text.
     * @param int    $limit  Maximum number of rows to return.
     * @param int    $offset How many matching rows to skip (pagination).
     *
     * @return list<SearchIndexRow>
     */
    public function search(string $query, int $limit, int $offset): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::COLUMNS is a fixed internal constant (never caller-supplied), not a value; every actual value is still a %s/%i/%d-bound argument.
                'SELECT ' . self::COLUMNS . ', MATCH(title, description) AGAINST (%s IN NATURAL LANGUAGE MODE)'
                    . ' AS relevance FROM %i'
                    . ' WHERE MATCH(title, description) AGAINST (%s IN NATURAL LANGUAGE MODE)'
                    . ' ORDER BY relevance DESC LIMIT %d OFFSET %d',
                $query,
                $wpdb->prefix . 'tube_search_index',
                $query,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        // Same documented wordpress-stubs gap as VideoViewsRepository::window_sums().
        /** @var array<int, array<string, string|null>> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $rows = (array) $rows;

        return self::hydrate_all($rows);
    }

    /**
     * {@inheritDoc}
     *
     * @param CandidateColumn $column Which JSON-array column to match against.
     * @param int             $id     The category/tag/actor/studio ID a row must contain.
     * @param int             $limit  Maximum number of rows to return.
     * @param int             $offset How many matching rows to skip (pagination).
     *
     * @return list<SearchIndexRow>
     *
     * @throws RuntimeException If the query template is malformed (a bug in this method, not in any argument).
     */
    public function list_by_column(CandidateColumn $column, int $id, int $limit, int $offset): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $column_name = $column->value;

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- self::COLUMNS is a fixed internal constant; $column_name is a closed CandidateColumn enum value. Neither is ever external input, and every actual value is still a %i/%s/%d-bound argument below.
            'SELECT ' . self::COLUMNS . " FROM %i WHERE JSON_CONTAINS({$column_name}, %s)"
                . ' ORDER BY published_at DESC LIMIT %d OFFSET %d',
            $wpdb->prefix . 'tube_search_index',
            (string) $id,
            $limit,
            $offset
        );

        if (null === $sql) {
            throw new RuntimeException(
                'wpdb::prepare() returned null for the list_by_column() query in ' . self::class . '.'
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above.
        $rows = $wpdb->get_results($sql, ARRAY_A);

        // Same documented wordpress-stubs gap as VideoViewsRepository::window_sums().
        /** @var array<int, array<string, string|null>> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $rows = (array) $rows;

        return self::hydrate_all($rows);
    }

    /**
     * {@inheritDoc}
     *
     * @param CandidateColumn $column Which JSON-array column to match against.
     * @param int             $id     The category/tag/actor/studio ID a row must contain.
     *
     * @throws RuntimeException If the query template is malformed (a bug in this method, not in any argument).
     */
    public function count_by_column(CandidateColumn $column, int $id): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $column_name = $column->value;

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $column_name is a closed CandidateColumn enum value, never external input; the actual value is still a %i/%s-bound argument below.
            "SELECT COUNT(*) FROM %i WHERE JSON_CONTAINS({$column_name}, %s)",
            $wpdb->prefix . 'tube_search_index',
            (string) $id
        );

        if (null === $sql) {
            throw new RuntimeException(
                'wpdb::prepare() returned null for the count_by_column() query in ' . self::class . '.'
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above.
        $count = $wpdb->get_var($sql);

        return null === $count ? 0 : (int) $count;
    }

    /**
     * Turn every row in a result set into a SearchIndexRow.
     *
     * @param array<int, array<string, string|null>> $rows Raw $wpdb rows.
     *
     * @return list<SearchIndexRow>
     */
    private static function hydrate_all(array $rows): array
    {
        return array_values(array_map(self::hydrate(...), $rows));
    }

    /**
     * Turn one raw $wpdb row into a SearchIndexRow.
     *
     * Same documented wordpress-stubs gap as
     * `Tube_Core\Views\Repositories\VideoViewsRepository::window_sums()`
     * for the inline `@var` narrowing below.
     *
     * @param array<string, string|null> $row One raw $wpdb row.
     */
    private static function hydrate(array $row): SearchIndexRow
    {
        /** @var array{video_id: string, title: string, description: string|null, category_ids: string|null, tag_ids: string|null, actor_ids: string|null, studio_ids: string|null, duration_seconds: string|null, views_total: string, published_at: string|null} $row */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        return new SearchIndexRow(
            (int) $row['video_id'],
            $row['title'],
            $row['description'],
            self::decode_ids($row['category_ids']),
            self::decode_ids($row['tag_ids']),
            self::decode_ids($row['actor_ids']),
            self::decode_ids($row['studio_ids']),
            null === $row['duration_seconds'] ? null : (int) $row['duration_seconds'],
            (int) $row['views_total'],
            $row['published_at']
        );
    }

    /**
     * JSON-encode a list of IDs for storage, or null for an empty list
     * (matching the column's `NULL`-able definition rather than storing
     * a meaningless `[]`).
     *
     * @param int[] $ids The IDs to encode.
     */
    private static function encode_ids(array $ids): ?string
    {
        if ([] === $ids) {
            return null;
        }

        $encoded = wp_json_encode(array_values($ids));

        return false === $encoded ? null : $encoded;
    }

    /**
     * Decode a stored JSON-array-of-IDs column back into a list of ints.
     *
     * @param string|null $value The raw column value.
     *
     * @return int[]
     */
    private static function decode_ids(?string $value): array
    {
        if (null === $value) {
            return [];
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $id): int => is_scalar($id) ? (int) $id : 0, $decoded));
    }
}
