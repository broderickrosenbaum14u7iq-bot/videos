<?php
/**
 * Data access for wp_tube_comment_root_locks.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Comments\Repositories;

use Tube_Comments\Support\Params;

/**
 * Data access for `wp_tube_comment_root_locks` — one row per
 * (user_id, video_id) pair, the race-safe enforcement mechanism behind
 * "at most one root comment per video per rolling 24-hour window"
 * (`SpamPolicy::ROOT_COMMENT_WINDOW_SECONDS`).
 *
 * Deliberately a SEPARATE table from `wp_tube_comments`, not a query
 * against comment rows: a plain `SELECT ... WHERE user_id = ? AND
 * video_id = ? AND parent_id IS NULL AND created_at >= ?` followed by a
 * conditional `INSERT` is a classic check-then-act race — two
 * concurrent requests can both pass the SELECT before either commits
 * its INSERT, both succeeding. This table's `PRIMARY KEY (user_id,
 * video_id)` plus a single atomic `INSERT ... ON DUPLICATE KEY UPDATE`
 * statement (see {@see self::try_acquire()}) closes that window
 * entirely: InnoDB takes an exclusive lock on the matched/inserted
 * primary-key record for the duration of the statement, so two
 * concurrent attempts for the identical pair are serialized by the
 * database itself, not by application code.
 *
 * Being a separate table (rather than, say, a `last_root_comment_at`
 * column derived from `wp_tube_comments` rows) also means editing or
 * deleting the actual comment row never touches this slot — Phase
 * "editing/deleting must not reset the anti-spam window" is satisfied
 * by construction, since `CommentService::update()`/`delete()` have no
 * reason to ever reach this repository at all.
 */
final class CommentRootLockRepository
{
    /**
     * Atomically check-and-acquire this user's root-comment slot for
     * $video_id: if no slot exists, or the existing slot's window has
     * already expired, records $video_id => now and returns true
     * (acquired — creation may proceed); if an active slot already
     * exists, leaves it untouched and returns false (blocked).
     *
     * @param int $user_id        The commenting member.
     * @param int $video_id       The video being commented on.
     * @param int $window_seconds The rolling window length (`SpamPolicy::ROOT_COMMENT_WINDOW_SECONDS`).
     */
    public function try_acquire(int $user_id, int $video_id, int $window_seconds): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table  = $wpdb->prefix . 'tube_comment_root_locks';
        $now    = current_time('mysql', true);
        $cutoff = gmdate('Y-m-d H:i:s', time() - $window_seconds);

        // The MySQL/MariaDB C-API's affected-rows semantics for
        // INSERT ... ON DUPLICATE KEY UPDATE are exactly what this
        // method's atomicity relies on: 1 row affected means a brand
        // new row was inserted, 2 means an existing row's value was
        // actually changed by the UPDATE branch, and 0 means an
        // existing row was matched but the UPDATE branch left it
        // unchanged (the IF() below evaluated to the column's own
        // current value) -- $wpdb->rows_affected exposes this directly.
        $sql = 'INSERT INTO %i (user_id, video_id, created_at) VALUES (%d, %d, %s)
            ON DUPLICATE KEY UPDATE created_at = IF(created_at <= %s, VALUES(created_at), created_at)';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a literal template built above, never request input.
        $prepared = Params::required_sql($wpdb->prepare($sql, $table, $user_id, $video_id, $now, $cutoff));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $prepared is Params::required_sql()'s narrowed wrapper around $wpdb->prepare() above, which this sniff's static analysis can't trace through.
        $wpdb->query($prepared);

        return $wpdb->rows_affected > 0;
    }

    /**
     * The ISO 8601 instant $user_id may next create a root comment on
     * $video_id, or null if they may do so right now (no slot exists,
     * or its window has already expired). Read-only — never mutates the
     * slot, unlike {@see self::try_acquire()} — used both to answer "is
     * the composer currently blocked" for a page load and to build the
     * `available_at` field on a blocked creation's error response.
     *
     * @param int $user_id        The member to check.
     * @param int $video_id       The video to check.
     * @param int $window_seconds The rolling window length (`SpamPolicy::ROOT_COMMENT_WINDOW_SECONDS`).
     */
    public function available_at(int $user_id, int $video_id, int $window_seconds): ?string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comment_root_locks';

        $sql = Params::required_sql(
            $wpdb->prepare('SELECT created_at FROM %i WHERE user_id = %d AND video_id = %d', $table, $user_id, $video_id)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql is Params::required_sql()'s narrowed wrapper around $wpdb->prepare() above, which this sniff's static analysis can't trace through.
        $created_at = $wpdb->get_var($sql);

        if (! is_string($created_at) || '' === $created_at) {
            return null;
        }

        $created_timestamp = strtotime($created_at . ' UTC');

        if (false === $created_timestamp) {
            return null;
        }

        $available_timestamp = $created_timestamp + $window_seconds;

        if ($available_timestamp <= time()) {
            return null;
        }

        return gmdate('c', $available_timestamp);
    }
}
