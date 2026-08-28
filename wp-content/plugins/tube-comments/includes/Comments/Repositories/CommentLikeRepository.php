<?php
/**
 * Data access for wp_tube_comment_likes.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Comments\Repositories;

use Tube_Comments\Support\Params;

/**
 * Data access for `wp_tube_comment_likes` — see
 * `Migration002CreateCommentLikesTable`'s docblock. User-only (no guest
 * identity, unlike `Tube_Core\Likes\Repositories\LikeRepository`):
 * commenting/liking always requires an authenticated member (Phase 12).
 */
final class CommentLikeRepository
{
    /**
     * Whether $user_id currently has an active like on $comment_id.
     *
     * @param int $user_id    The viewer.
     * @param int $comment_id The comment to check.
     */
    public function has_liked(int $user_id, int $comment_id): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comment_likes';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table (§2.5, §11).
        $found = $wpdb->get_var(
            $wpdb->prepare('SELECT 1 FROM %i WHERE user_id = %d AND comment_id = %d', $table, $user_id, $comment_id)
        );

        return null !== $found;
    }

    /**
     * Every comment ID (from $comment_ids) that $user_id currently likes
     * — the batch check `Http\CommentListController`/`CommentRepliesController`
     * use to avoid one `has_liked()` query per rendered comment (Phase
     * 30's "avoid N+1 queries").
     *
     * @param int   $user_id     The viewer.
     * @param array $comment_ids The comments to check.
     *
     * @phpstan-param list<int> $comment_ids
     *
     * @return list<int>
     */
    public function liked_comment_ids(int $user_id, array $comment_ids): array
    {
        if ([] === $comment_ids) {
            return [];
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table        = $wpdb->prefix . 'tube_comment_likes';
        $placeholders = implode(', ', array_fill(0, count($comment_ids), '%d'));
        $sql          = "SELECT comment_id FROM %i WHERE user_id = %d AND comment_id IN ({$placeholders})";
        $args         = array_merge([$table, $user_id], $comment_ids);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a literal template built above, never request input.
        $prepared = Params::required_sql($wpdb->prepare($sql, $args));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- dedicated custom table (§2.5, §11); $sql is a literal template built above (the {$placeholders} fragment is a fixed count of literal '%d' tokens, never request input), and $args' element count varies with count($comment_ids), which this sniff's static placeholder count can't follow.
        $ids = $wpdb->get_col($prepared);

        return array_values(array_map(static fn ($id): int => Params::int($id), $ids));
    }

    /**
     * Add a like row, if one doesn't already exist. Race-safe via
     * `wp_tube_comment_likes`'s own `UNIQUE KEY`.
     *
     * @param int $user_id    The viewer.
     * @param int $comment_id The comment being liked.
     *
     * @return bool True if a new row was actually inserted.
     */
    public function add(int $user_id, int $comment_id): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comment_likes';

        $sql = Params::required_sql(
            $wpdb->prepare(
                'INSERT IGNORE INTO %i (comment_id, user_id, created_at) VALUES (%d, %d, %s)',
                $table,
                $comment_id,
                $user_id,
                current_time('mysql', true)
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql is Params::required_sql()'s narrowed wrapper around $wpdb->prepare() above, which this sniff's static analysis can't trace through.
        $wpdb->query($sql);

        return 1 === $wpdb->rows_affected;
    }

    /**
     * Remove a like row, if one exists.
     *
     * @param int $user_id    The viewer.
     * @param int $comment_id The comment being unliked.
     *
     * @return bool True if a row was actually deleted.
     */
    public function remove(int $user_id, int $comment_id): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comment_likes';

        $sql = Params::required_sql(
            $wpdb->prepare('DELETE FROM %i WHERE user_id = %d AND comment_id = %d', $table, $user_id, $comment_id)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql is Params::required_sql()'s narrowed wrapper around $wpdb->prepare() above, which this sniff's static analysis can't trace through.
        $deleted = $wpdb->query($sql);

        return is_int($deleted) && $deleted > 0;
    }
}
