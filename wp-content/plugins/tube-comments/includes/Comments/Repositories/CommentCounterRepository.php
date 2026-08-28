<?php
/**
 * Data access for wp_tube_comment_counters.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Comments\Repositories;

use Tube_Comments\Support\Params;

/**
 * Data access for `wp_tube_comment_counters` — see
 * `Migration004CreateCommentCountersTable`'s docblock for the exact
 * counting policy ("💬 Bình luận N" = published root comments AND
 * published replies together).
 */
final class CommentCounterRepository
{
    /**
     * This video's current comment count — a single indexed primary-key
     * read, the same cost class `Tube_Core\Views\Repositories\
     * VideoStatisticsRepositoryInterface::likes_total()` already accepts
     * for a single-video page render (Phase 25: "avoid COUNT(*) across
     * huge tables on every request").
     *
     * @param int $video_id The video post ID.
     */
    public function get(int $video_id): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comment_counters';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table (§2.5, §11).
        $count = $wpdb->get_var(
            $wpdb->prepare('SELECT comments_total FROM %i WHERE video_id = %d', $table, $video_id)
        );

        return null === $count ? 0 : (int) $count;
    }

    /**
     * Atomically add 1 to a video's comment count, creating its counter
     * row (seeded at 1) if this is the video's first published comment
     * — one `INSERT ... ON DUPLICATE KEY UPDATE`, never a read-then-write.
     *
     * @param int $video_id The video post ID.
     */
    public function increment(int $video_id): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comment_counters';
        $now   = current_time('mysql', true);

        $sql = Params::required_sql(
            $wpdb->prepare(
                'INSERT INTO %i (video_id, comments_total, updated_at) VALUES (%d, 1, %s)
                    ON DUPLICATE KEY UPDATE comments_total = comments_total + 1, updated_at = VALUES(updated_at)',
                $table,
                $video_id,
                $now
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql is Params::required_sql()'s narrowed wrapper around $wpdb->prepare() above, which this sniff's static analysis can't trace through.
        $wpdb->query($sql);
    }

    /**
     * Atomically subtract 1 from a video's comment count, floored at 0.
     *
     * @param int $video_id The video post ID.
     */
    public function decrement(int $video_id): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comment_counters';

        $sql = Params::required_sql(
            $wpdb->prepare(
                'UPDATE %i SET comments_total = GREATEST(0, comments_total - 1), updated_at = %s WHERE video_id = %d',
                $table,
                current_time('mysql', true),
                $video_id
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql is Params::required_sql()'s narrowed wrapper around $wpdb->prepare() above, which this sniff's static analysis can't trace through.
        $wpdb->query($sql);
    }
}
