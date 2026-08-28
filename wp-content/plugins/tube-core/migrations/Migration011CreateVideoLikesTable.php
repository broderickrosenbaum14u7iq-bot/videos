<?php
/**
 * Creates wp_tube_video_likes.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Creates wp_tube_video_likes — one row per (viewer, video) like, per the
 * mobile watch-page redesign's real Like system. Same shape as
 * `Migration008CreateWatchHistoryTable`'s `wp_tube_watch_history`: a
 * logged-in viewer's row carries `user_id` (NULL `visitor_token`), a
 * guest's carries `visitor_token` (NULL `user_id`) — never both — and
 * the two separate `UNIQUE KEY`s are what make "one active like per
 * viewer/video" a real database guarantee (MySQL `UNIQUE KEY`s ignore
 * `NULL`, so guest rows never collide with each other on the ignored
 * `user_id` column, and vice versa) rather than an application-level
 * check-then-write race. `Tube_Core\Likes\Repositories\LikeRepository::add()`
 * relies on this directly: an `INSERT IGNORE` against this key is what
 * makes a double-tap race-safe without a lock.
 *
 * Deliberately a separate table from `wp_tube_watch_history` rather than
 * an extra column there — a like is a distinct, independent viewer
 * action from watch progress (liking doesn't imply any particular
 * progress, and progress updates on every player tick, which would
 * otherwise contend with a `saved`/`liked` flag on the same row for no
 * reason).
 */
final class Migration011CreateVideoLikesTable extends AbstractMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '011';
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Create wp_tube_video_likes (one row per viewer/video like, guest + logged-in).';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $table           = $this->db()->prefix . 'tube_video_likes';
        $charset_collate = $this->charset_collate();

        $this->apply_schema(
            "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                video_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED DEFAULT NULL,
                visitor_token VARCHAR(64) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY user_video_idx (user_id, video_id),
                UNIQUE KEY visitor_video_idx (visitor_token, video_id),
                KEY video_id_idx (video_id)
            ) {$charset_collate};"
        );
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $this->drop_table($this->db()->prefix . 'tube_video_likes');
    }
}
