<?php
/**
 * Data access for wp_tube_watch_history (WatchHistoryRepositoryInterface).
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\WatchHistory\Repositories;

use RuntimeException;

/**
 * Data access for wp_tube_watch_history (WatchHistoryRepositoryInterface).
 *
 * Direct $wpdb access is the same documented, intentional exception
 * every dedicated-table repository in this project uses (ARCHITECTURE.md
 * §2.5/§11). Every write here is a single-row upsert with a fixed
 * placeholder count, so — unlike the bulk multi-row writes elsewhere in
 * this project (`ARCHITECTURE.md` §19.8) — these use `$wpdb->prepare()`
 * normally; there's no variable-length `VALUES` clause here for its
 * `literal-string` requirement to conflict with.
 *
 * Every `$wpdb->prepare()` result is assigned to a variable and
 * null-guarded before being passed to `$wpdb->query()` (rather than
 * nested inline, or routed through a shared private helper) — this is
 * the one shape both PHPStan (`wpdb::query()` rejects `string|null`) and
 * WPCS (its prepared-query sniff only recognises `$wpdb->prepare(`
 * called directly, not through an indirection) will accept
 * simultaneously; confirmed empirically after a wrapper-method version
 * of this passed PHPStan but broke WPCS's detection entirely.
 */
final class WatchHistoryRepository implements WatchHistoryRepositoryInterface
{
    /**
     * {@inheritDoc}
     *
     * @param int  $user_id          The logged-in user's ID.
     * @param int  $video_id         The video being watched.
     * @param int  $progress_seconds How far into the video the viewer has watched.
     * @param bool $completed        Whether the viewer finished the video.
     *
     * @throws RuntimeException If the query template is malformed (a bug in this method, not in any argument).
     */
    public function upsert_for_user(int $user_id, int $video_id, int $progress_seconds, bool $completed): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $now = current_time('mysql', true);

        $sql = $wpdb->prepare(
            'INSERT INTO %i (video_id, user_id, visitor_token, progress_seconds, completed, created_at, updated_at)'
            . ' VALUES (%d, %d, NULL, %d, %d, %s, %s)'
            . ' ON DUPLICATE KEY UPDATE progress_seconds = VALUES(progress_seconds),'
            . ' completed = VALUES(completed), updated_at = VALUES(updated_at)',
            $wpdb->prefix . 'tube_watch_history',
            $video_id,
            $user_id,
            $progress_seconds,
            (int) $completed,
            $now,
            $now
        );

        // wpdb::prepare() returns null only for a malformed literal format
        // string above (a bug in this method, not something any argument
        // could trigger) — guarded rather than asserted away, since
        // wpdb::query() itself is typed to reject null (the same pattern
        // VideoViewsRepository::purge_before() already uses).
        if (null === $sql) {
            throw new RuntimeException('wpdb::prepare() returned null in ' . __METHOD__ . '().');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above -- the sniff's pattern-matching doesn't follow it through the null-guard branch above (same as VideoViewsRepository::purge_before()).
        $wpdb->query($sql);
    }

    /**
     * {@inheritDoc}
     *
     * @param string $visitor_token    The guest's visitor token.
     * @param int    $video_id         The video being watched.
     * @param int    $progress_seconds How far into the video the viewer has watched.
     * @param bool   $completed        Whether the viewer finished the video.
     *
     * @throws RuntimeException If the query template is malformed (a bug in this method, not in any argument).
     */
    public function upsert_for_guest(string $visitor_token, int $video_id, int $progress_seconds, bool $completed): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $now = current_time('mysql', true);

        $sql = $wpdb->prepare(
            'INSERT INTO %i (video_id, user_id, visitor_token, progress_seconds, completed, created_at, updated_at)'
            . ' VALUES (%d, NULL, %s, %d, %d, %s, %s)'
            . ' ON DUPLICATE KEY UPDATE progress_seconds = VALUES(progress_seconds),'
            . ' completed = VALUES(completed), updated_at = VALUES(updated_at)',
            $wpdb->prefix . 'tube_watch_history',
            $video_id,
            $visitor_token,
            $progress_seconds,
            (int) $completed,
            $now,
            $now
        );

        // See upsert_for_user()'s identical null-guard comment for why.
        if (null === $sql) {
            throw new RuntimeException('wpdb::prepare() returned null in ' . __METHOD__ . '().');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above -- the sniff's pattern-matching doesn't follow it through the null-guard branch above (same as VideoViewsRepository::purge_before()).
        $wpdb->query($sql);
    }

    /**
     * {@inheritDoc}
     *
     * @param string $cutoff MySQL `DATETIME` string.
     *
     * @throws RuntimeException If the query template is malformed (a bug in this method, not in $cutoff).
     */
    public function purge_stale_guests(string $cutoff): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $sql = $wpdb->prepare(
            'DELETE FROM %i WHERE visitor_token IS NOT NULL AND updated_at < %s',
            $wpdb->prefix . 'tube_watch_history',
            $cutoff
        );

        // See upsert_for_user()'s identical null-guard comment for why.
        if (null === $sql) {
            throw new RuntimeException('wpdb::prepare() returned null in ' . __METHOD__ . '().');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above -- the sniff's pattern-matching doesn't follow it through the null-guard branch above (same as VideoViewsRepository::purge_before()).
        $deleted = $wpdb->query($sql);

        return (int) $deleted;
    }
}
