<?php
/**
 * Data access for wp_tube_video_statistics (VideoStatisticsRepositoryInterface) implementation.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Views\Repositories;

use RuntimeException;

/**
 * Data access for wp_tube_video_statistics (VideoStatisticsRepositoryInterface)
 * implementation. Direct $wpdb access is the same documented, intentional
 * exception every dedicated-table repository in this project uses
 * (ARCHITECTURE.md §2.5/§11) — no WP_Query/WP_Meta_Query equivalent exists
 * for these tables.
 */
final class VideoStatisticsRepository implements VideoStatisticsRepositoryInterface
{
    /**
     * {@inheritDoc}
     *
     * @param array<int, int> $counts Video ID => view count to add to views_total.
     */
    public function bump_totals(array $counts): void
    {
        if ([] === $counts) {
            return;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table        = $wpdb->prefix . 'tube_video_statistics';
        $quoted_now   = "'" . esc_sql(current_time('mysql', true)) . "'";
        $value_tuples = [];

        foreach ($counts as $video_id => $count) {
            $value_tuples[] = sprintf('(%d, %d, 0, 0, 0, %s)', $video_id, $count, $quoted_now);
        }

        // Not built with $wpdb->prepare() -- same variable-length-VALUES
        // justification VideoViewsRepository::bulk_record() documents in
        // full; every value here is a PHP int or esc_sql()'d.
        $sql = 'INSERT INTO ' . $table . ' (video_id, views_total, views_today, views_7d, views_30d, updated_at)'
            . ' VALUES ' . implode(', ', $value_tuples)
            . ' ON DUPLICATE KEY UPDATE views_total = views_total + VALUES(views_total),'
            . ' updated_at = VALUES(updated_at)';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- see the comment above $sql's assignment.
        $wpdb->query($sql);
    }

    /**
     * {@inheritDoc}
     *
     * @return array<int, int> Video ID => current views_total.
     */
    public function all_totals(): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT video_id, views_total FROM %i', $wpdb->prefix . 'tube_video_statistics'),
            ARRAY_A
        );

        // Same documented wordpress-stubs gap as VideoViewsRepository::window_sums().
        /** @var array<int, array{video_id: string, views_total: string}> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $rows = (array) $rows;

        $result = [];

        foreach ($rows as $row) {
            $result[ (int) $row['video_id'] ] = (int) $row['views_total'];
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int, array{today: int, d7: int, d30: int}> $windows Video ID => window values.
     */
    public function update_windows(array $windows): void
    {
        if ([] === $windows) {
            return;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table        = $wpdb->prefix . 'tube_video_statistics';
        $quoted_now   = "'" . esc_sql(current_time('mysql', true)) . "'";
        $value_tuples = [];

        foreach ($windows as $video_id => $window) {
            $value_tuples[] = sprintf(
                '(%d, 0, %d, %d, %d, %s)',
                $video_id,
                $window['today'],
                $window['d7'],
                $window['d30'],
                $quoted_now
            );
        }

        // Not built with $wpdb->prepare() -- same variable-length-VALUES
        // justification VideoViewsRepository::bulk_record() documents in
        // full; every value here is a PHP int or esc_sql()'d.
        $sql = 'INSERT INTO ' . $table . ' (video_id, views_total, views_today, views_7d, views_30d, updated_at)'
            . ' VALUES ' . implode(', ', $value_tuples)
            . ' ON DUPLICATE KEY UPDATE views_today = VALUES(views_today), views_7d = VALUES(views_7d),'
            . ' views_30d = VALUES(views_30d), updated_at = VALUES(updated_at)';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- see the comment above $sql's assignment.
        $wpdb->query($sql);
    }

    /**
     * {@inheritDoc}
     *
     * @param int $limit Maximum number of videos to return.
     *
     * @return list<array{video_id: int, count: int}> Highest `views_total` first.
     */
    public function top_by_views_total(int $limit): array
    {
        return $this->top_by('views_total', $limit);
    }

    /**
     * {@inheritDoc}
     *
     * @param int $limit Maximum number of videos to return.
     *
     * @return list<array{video_id: int, count: int}> Highest `views_7d` first.
     */
    public function top_by_views_7d(int $limit): array
    {
        return $this->top_by('views_7d', $limit);
    }

    /**
     * {@inheritDoc}
     *
     * @param 'views_total'|'views_today'|'views_7d'|'views_30d' $order_by Column to sort by, highest first.
     * @param int                                                $limit    Maximum number of videos to return.
     * @param int                                                $offset   Number of videos to skip, for pagination.
     *
     * @return list<array{video_id: int, views_total: int, views_today: int, views_7d: int, views_30d: int}>
     */
    public function list_all(string $order_by, int $limit, int $offset): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // $order_by is constrained by this method's own PHPStan literal-union
        // parameter type, never caller-raw SQL text -- the same "fixed
        // literal, not caller-supplied" justification self::top_by() already
        // documents for its own {$column} interpolation.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT video_id, views_total, views_today, views_7d, views_30d FROM %i'
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- see the comment above.
                    . " ORDER BY {$order_by} DESC LIMIT %d OFFSET %d",
                $wpdb->prefix . 'tube_video_statistics',
                $limit,
                $offset
            ),
            ARRAY_A
        );

        /** @var array<int, array{video_id: string, views_total: string, views_today: string, views_7d: string, views_30d: string}> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $rows = (array) $rows;

        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'video_id'    => (int) $row['video_id'],
                'views_total' => (int) $row['views_total'],
                'views_today' => (int) $row['views_today'],
                'views_7d'    => (int) $row['views_7d'],
                'views_30d'   => (int) $row['views_30d'],
            ];
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function count_all(): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'tube_video_statistics'));

        return null === $count ? 0 : (int) $count;
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video post ID.
     */
    public function likes_total(int $video_id): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $value = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT likes_total FROM %i WHERE video_id = %d',
                $wpdb->prefix . 'tube_video_statistics',
                $video_id
            )
        );

        return null === $value ? 0 : (int) $value;
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video post ID.
     *
     * @throws RuntimeException If the query template is malformed (a bug in this method, not in any argument).
     */
    public function increment_likes(int $video_id): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $sql = $wpdb->prepare(
            'UPDATE %i SET likes_total = likes_total + 1, updated_at = %s WHERE video_id = %d',
            $wpdb->prefix . 'tube_video_statistics',
            current_time('mysql', true),
            $video_id
        );

        if (null === $sql) {
            throw new RuntimeException('wpdb::prepare() returned null in ' . __METHOD__ . '().');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above.
        $wpdb->query($sql);
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video post ID.
     *
     * @throws RuntimeException If the query template is malformed (a bug in this method, not in any argument).
     */
    public function decrement_likes(int $video_id): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // GREATEST(..., 0) floors the counter at 0 -- likes_total can
        // never go negative even if decrement_likes() were ever somehow
        // called without a matching prior increment_likes().
        $sql = $wpdb->prepare(
            'UPDATE %i SET likes_total = GREATEST(likes_total - 1, 0), updated_at = %s WHERE video_id = %d',
            $wpdb->prefix . 'tube_video_statistics',
            current_time('mysql', true),
            $video_id
        );

        if (null === $sql) {
            throw new RuntimeException('wpdb::prepare() returned null in ' . __METHOD__ . '().');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above.
        $wpdb->query($sql);
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video post ID.
     * @param int $baseline The `views_total` to seed a brand-new row with.
     *
     * @throws RuntimeException If the query template is malformed (a bug in this method, not in any argument).
     */
    public function ensure_baseline(int $video_id, int $baseline): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $sql = $wpdb->prepare(
            'INSERT INTO %i (video_id, views_total, views_today, views_7d, views_30d, updated_at)'
                . ' VALUES (%d, %d, 0, 0, 0, %s)'
                . ' ON DUPLICATE KEY UPDATE video_id = video_id',
            $wpdb->prefix . 'tube_video_statistics',
            $video_id,
            $baseline,
            current_time('mysql', true)
        );

        if (null === $sql) {
            throw new RuntimeException(
                'wpdb::prepare() returned null for the ensure_baseline() query in ' . self::class . '.'
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above.
        $wpdb->query($sql);
    }

    /**
     * Shared implementation behind self::top_by_views_total()/self::top_by_views_7d():
     * an indexed `ORDER BY {$column} DESC LIMIT` — the column name is
     * never caller-supplied (both public methods pass a fixed literal),
     * so this is safe despite not being a `%i`-prepared identifier.
     *
     * @param 'views_total'|'views_7d' $column The column to sort by — always views_total_idx or views_7d_idx.
     * @param int                      $limit  Maximum number of videos to return.
     *
     * @return list<array{video_id: int, count: int}> Highest value first.
     */
    private function top_by(string $column, int $limit): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // {$column} is never caller-supplied -- both public methods above
        // pass a fixed literal ('views_total'/'views_7d'), never a value
        // that could originate from a request; %i/%d cover every value
        // that IS variable here.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see comment above.
                "SELECT video_id, {$column} AS count FROM %i ORDER BY {$column} DESC LIMIT %d",
                $wpdb->prefix . 'tube_video_statistics',
                $limit
            ),
            ARRAY_A
        );

        // Same documented wordpress-stubs gap as VideoViewsRepository::window_sums().
        /** @var array<int, array{video_id: string, count: string}> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $rows = (array) $rows;

        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'video_id' => (int) $row['video_id'],
                'count'    => (int) $row['count'],
            ];
        }

        return $result;
    }
}
