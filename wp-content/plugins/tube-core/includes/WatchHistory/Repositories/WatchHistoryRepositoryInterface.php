<?php
/**
 * Contract for wp_tube_watch_history data access.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\WatchHistory\Repositories;

/**
 * Contract for wp_tube_watch_history data access, per the
 * `{Noun}Repository` convention (ARCHITECTURE.md §19.4).
 *
 * Adopted per the interface-justification rule (§19.1): the real payoff
 * is a test fake `WatchHistoryRecorder` is actually unit-tested against,
 * without a live database.
 */
interface WatchHistoryRepositoryInterface
{
    /**
     * Record or update a logged-in user's progress on a video — a single
     * upsert against the `(user_id, video_id)` unique key
     * (`Migration008CreateWatchHistoryTable`), never a duplicate row.
     *
     * @param int  $user_id          The logged-in user's ID.
     * @param int  $video_id         The video being watched.
     * @param int  $progress_seconds How far into the video the viewer has watched.
     * @param bool $completed        Whether the viewer finished the video.
     */
    public function upsert_for_user(int $user_id, int $video_id, int $progress_seconds, bool $completed): void;

    /**
     * Record or update a guest's progress on a video — a single upsert
     * against the `(visitor_token, video_id)` unique key, never a
     * duplicate row.
     *
     * @param string $visitor_token    The guest's visitor token (see `VisitorToken`).
     * @param int    $video_id         The video being watched.
     * @param int    $progress_seconds How far into the video the viewer has watched.
     * @param bool   $completed        Whether the viewer finished the video.
     */
    public function upsert_for_guest(
        string $visitor_token,
        int $video_id,
        int $progress_seconds,
        bool $completed
    ): void;

    /**
     * Purge guest history rows untouched since before $cutoff.
     *
     * Logged-in users' history is never purged this way — it's tied to
     * their account, not a stale-anonymous-tracking concern.
     *
     * @param string $cutoff MySQL `DATETIME` string.
     *
     * @return int Number of rows deleted.
     */
    public function purge_stale_guests(string $cutoff): int;
}
