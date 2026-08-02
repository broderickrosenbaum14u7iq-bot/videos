<?php
/**
 * Data access for wp_tube_video_statistics (VideoStatisticsRepositoryInterface) implementation.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Views\Repositories;

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
}
