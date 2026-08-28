<?php
/**
 * Data access for wp_tube_comment_reports.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Comments\Repositories;

use Tube_Comments\Support\Params;

/**
 * Data access for `wp_tube_comment_reports` — see
 * `Migration003CreateCommentReportsTable`'s docblock. A report is a pure
 * append; nothing here ever writes back onto `wp_tube_comments`.
 */
final class CommentReportRepository
{
    /**
     * Record a report, if this reporter hasn't already reported this
     * comment. Race-safe/duplicate-proof via `wp_tube_comment_reports`'s
     * own `UNIQUE KEY` (Phase 17: "Prevent one account from generating
     * unlimited duplicate reports on the same comment").
     *
     * @param int                                         $comment_id       The comment being reported.
     * @param int                                         $reporter_user_id The reporting member.
     * @param 'spam'|'inappropriate'|'harassment'|'other' $reason           The report reason.
     *
     * @return bool True if a new report row was actually inserted; false if this reporter already reported it.
     */
    public function add(int $comment_id, int $reporter_user_id, string $reason): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comment_reports';

        $sql = Params::required_sql(
            $wpdb->prepare(
                'INSERT IGNORE INTO %i (comment_id, reporter_user_id, reason, created_at) VALUES (%d, %d, %s, %s)',
                $table,
                $comment_id,
                $reporter_user_id,
                $reason,
                current_time('mysql', true)
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql is Params::required_sql()'s narrowed wrapper around $wpdb->prepare() above, which this sniff's static analysis can't trace through.
        $wpdb->query($sql);

        return 1 === $wpdb->rows_affected;
    }

    /**
     * Total number of distinct reports against $comment_id — the
     * moderation screen's "Reports" column (Phase 22).
     *
     * @param int $comment_id The comment ID.
     */
    public function count_for_comment(int $comment_id): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comment_reports';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table (§2.5, §11).
        $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE comment_id = %d', $table, $comment_id));

        return null === $count ? 0 : (int) $count;
    }

    /**
     * Every comment ID with at least one report, newest-reported first
     * — the moderation screen's "Reported" filter (Phase 22).
     *
     * @param int $limit  Maximum number of comment IDs to return.
     * @param int $offset Number of comment IDs to skip, for pagination.
     *
     * @return list<int>
     */
    public function reported_comment_ids(int $limit, int $offset): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comment_reports';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table (§2.5, §11); admin-only, low-QPS moderation read.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT comment_id FROM %i GROUP BY comment_id ORDER BY MAX(id) DESC LIMIT %d OFFSET %d',
                $table,
                $limit,
                $offset
            )
        );

        return array_values(array_map(static fn ($id): int => Params::int($id), $ids));
    }
}
