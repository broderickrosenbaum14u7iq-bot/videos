<?php
/**
 * Deletes every comment-related row for a video when it's permanently deleted.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Events;

use Tube_Comments\Support\Params;

/**
 * Deletes every one of this plugin's rows for a video the moment it's
 * permanently deleted (release-audit CRIT-3).
 *
 * Subscribes via WordPress's native `add_action()` on tube-core's
 * documented, versioned hook-name string (ARCHITECTURE.md §6) —
 * deliberately *not* by depending on `Tube_Core\Events\Dispatcher`/
 * `EventCatalog` as PHP types, for the same reasons
 * `Tube_Search\Events\SearchIndexSyncSubscriber`/`Tube_Cache\Events\CachePurgeSubscriber`
 * already document: hook-name-string subscription works whether tube-core
 * happens to be active or not, keeps this plugin's own PHPUnit suite free
 * of a real `Tube_Core\*` dependency, and depends on exactly the
 * documented public contract (the event name) rather than tube-core's
 * internal classes.
 *
 * `wp_tube_comment_likes`/`wp_tube_comment_reports` have no `video_id`
 * column of their own (only `comment_id`), so cleanup here is two-step:
 * collect this video's comment IDs from `wp_tube_comments` first, then
 * delete the comment-ID-keyed rows before the comments themselves.
 */
final class VideoDeletionCascadeSubscriber
{
    /**
     * Must match Tube_Core\Events\EventCatalog::VIDEO_DELETED exactly.
     */
    private const VIDEO_DELETED = 'tube_core.video.deleted';

    /**
     * Wire this class's handler to tube-core's hook.
     *
     * Every dispatch from Tube_Core\Events\WordPressHookBus passes exactly
     * one argument (the event payload array), so $accepted_args is always
     * 1 here — the same contract CachePurgeSubscriber/SearchIndexSyncSubscriber
     * rely on from the dispatching side.
     */
    public function register(): void
    {
        add_action(self::VIDEO_DELETED, [$this, 'handle_video_deleted'], 10, 1);
    }

    /**
     * `tube_core.video.deleted` handler: remove every comment-related row for this video.
     *
     * @param array<string, mixed> $payload Carries `video_id` per EVENTS.md.
     */
    public function handle_video_deleted(array $payload): void
    {
        $video_id = self::extract_video_id($payload);

        if (null === $video_id) {
            return;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $comments_table = $wpdb->prefix . 'tube_comments';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- %i/%d-prepared two lines below via $wpdb->prepare(), the sniff doesn't follow the assignment through.
        $comment_ids_sql = $wpdb->prepare('SELECT id FROM %i WHERE video_id = %d', $comments_table, $video_id);

        if (null === $comment_ids_sql) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $comment_ids_sql *is* $wpdb->prepare()'d above.
        $comment_ids = array_map(static fn ($id): int => Params::int($id), $wpdb->get_col($comment_ids_sql));

        if ([] !== $comment_ids) {
            $this->delete_by_comment_ids($wpdb, $wpdb->prefix . 'tube_comment_likes', $comment_ids);
            $this->delete_by_comment_ids($wpdb, $wpdb->prefix . 'tube_comment_reports', $comment_ids);
        }

        $this->delete_by_video_id($wpdb, $comments_table, $video_id);
        $this->delete_by_video_id($wpdb, $wpdb->prefix . 'tube_comment_counters', $video_id);
        $this->delete_by_video_id($wpdb, $wpdb->prefix . 'tube_comment_root_locks', $video_id);
    }

    /**
     * `DELETE FROM $table WHERE video_id = $video_id`.
     *
     * @param \wpdb  $wpdb     The global $wpdb instance.
     * @param string $table    Fully-prefixed table name.
     * @param int    $video_id The video whose rows to delete.
     */
    private function delete_by_video_id(\wpdb $wpdb, string $table, int $video_id): void
    {
        $sql = $wpdb->prepare('DELETE FROM %i WHERE video_id = %d', $table, $video_id);

        if (null === $sql) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d two lines above.
        $wpdb->query($sql);
    }

    /**
     * `DELETE FROM $table WHERE comment_id IN (...)`, for the two tables
     * (`wp_tube_comment_likes`/`_reports`) with no `video_id` column of
     * their own.
     *
     * @param \wpdb  $wpdb        The global $wpdb instance.
     * @param string $table       Fully-prefixed table name.
     * @param int[]  $comment_ids Non-empty list of this video's comment IDs.
     */
    private function delete_by_comment_ids(\wpdb $wpdb, string $table, array $comment_ids): void
    {
        $placeholders = implode(', ', array_fill(0, count($comment_ids), '%d'));

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $placeholders is a fixed-shape string of literal "%d" tokens, never external input; every actual value is still a %i/%d-bound argument below.
            "DELETE FROM %i WHERE comment_id IN ({$placeholders})",
            array_merge([$table], $comment_ids)
        );

        if (null === $sql) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above.
        $wpdb->query($sql);
    }

    /**
     * Extract `video_id` from an event payload — a malformed payload is
     * logged and ignored rather than left to throw out of a WordPress
     * hook callback, the same fail-open-and-log principle
     * `CachePurgeSubscriber::extract_video_id()` already applies.
     *
     * @param array<string, mixed> $payload The event payload.
     */
    private static function extract_video_id(array $payload): ?int
    {
        if (! isset($payload['video_id']) || ! is_numeric($payload['video_id'])) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate production logging for a malformed event payload that is otherwise silently ignored; not leftover debug code.
            error_log('[tube-comments] Event payload is missing a numeric "video_id".');

            return null;
        }

        return (int) $payload['video_id'];
    }
}
