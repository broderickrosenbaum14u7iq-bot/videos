<?php
/**
 * Deletes tube-core's own video-scoped rows when a video is permanently deleted.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Events;

/**
 * Deletes tube-core's own video-scoped rows the moment a video post is
 * permanently deleted (release-audit CRIT-3).
 *
 * `VIDEO_DELETED` fires from `VideoLifecycleEvents::handle_before_delete()`
 * on WordPress's `before_delete_post` — i.e. before the post row itself is
 * gone, but after WordPress has already committed to a hard delete (never
 * fired for a trash transition, which dispatches VIDEO_UPDATED instead).
 * Without this subscriber these five tables kept every row forever: no
 * user-visible symptom yet (no "my saved videos" listing exists to surface
 * a ghost `saved_videos` row), but a real data-integrity risk if a post ID
 * is ever reused (DB import, AUTO_INCREMENT reset) — a new unrelated video
 * would silently inherit the old one's stats/likes/saves.
 *
 * A dedicated small class, the same shape `ViewBaselineSubscriber` already
 * uses for "tube-core reacting to its own event" — same-plugin, so this
 * uses the typed `EventCatalog` constant directly rather than the raw
 * hook-name-string pattern `Tube_Search\Events\SearchIndexSyncSubscriber`/
 * `Tube_Comments\Events\VideoDeletionCascadeSubscriber` use to react to
 * tube-core's events from *outside* the plugin.
 *
 * Scoped to exactly the tables the release audit's CRIT-3 finding named
 * (`wp_tube_video_metadata`, `_views`, `_statistics`, `_likes`,
 * `wp_tube_saved_videos`) — not `wp_tube_video_actors`/`_studios` or
 * `wp_tube_watch_history`, which the audit didn't flag and which are out
 * of scope for this fix.
 */
final class VideoDeletionCascadeSubscriber
{
    /**
     * Wire this class's handler to `VIDEO_DELETED`. Called once from `Tube_Core\Plugin::boot()`.
     */
    public function register(): void
    {
        add_action(EventCatalog::VIDEO_DELETED, [$this, 'handle_video_deleted'], 10, 1);
    }

    /**
     * `tube_core.video.deleted` handler: delete every row this plugin
     * owns for the deleted video, across all five of its video-scoped
     * tables.
     *
     * @param array<string, mixed> $payload Carries `video_id` per EVENTS.md.
     */
    public function handle_video_deleted(array $payload): void
    {
        $video_id = $payload['video_id'] ?? null;

        if (! is_int($video_id)) {
            return;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $tables = $this->owned_tables($wpdb);

        foreach ($tables as $table) {
            $this->delete_by_video_id($wpdb, $table, $video_id);
        }
    }

    /**
     * Every table this plugin owns that's keyed (directly or via primary
     * key) by `video_id`.
     *
     * @param \wpdb $wpdb The global $wpdb instance.
     *
     * @return list<string> Fully-prefixed table names.
     */
    private function owned_tables(\wpdb $wpdb): array
    {
        return [
            $wpdb->prefix . 'tube_video_metadata',
            $wpdb->prefix . 'tube_video_views',
            $wpdb->prefix . 'tube_video_statistics',
            $wpdb->prefix . 'tube_video_likes',
            $wpdb->prefix . 'tube_saved_videos',
        ];
    }

    /**
     * `DELETE FROM $table WHERE video_id = $video_id`, matching the
     * `%i`/`%d`-prepared direct-table-name convention `VideoViewsRepository::purge_before()`
     * already establishes.
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
}
