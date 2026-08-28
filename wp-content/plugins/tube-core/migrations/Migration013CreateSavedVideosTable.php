<?php
/**
 * Creates wp_tube_saved_videos.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Creates wp_tube_saved_videos — one row per (viewer, video) "Watch
 * Later" save, per the mobile watch-page redesign's Save feature.
 * Identical shape to `Migration011CreateVideoLikesTable`'s
 * `wp_tube_video_likes` (see that migration's docblock for the
 * user_id-XOR-visitor_token/UNIQUE-KEY reasoning, which applies here
 * unchanged) — a separate table rather than a shared "engagement" table
 * with a type column, since a save carries no counter to keep atomically
 * in sync the way a like does, and keeping the two independent avoids a
 * WHERE-type-discriminated index on what would otherwise be a shared
 * table.
 */
final class Migration013CreateSavedVideosTable extends AbstractMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '013';
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Create wp_tube_saved_videos (one row per viewer/video "Watch Later" save).';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $table           = $this->db()->prefix . 'tube_saved_videos';
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
        $this->drop_table($this->db()->prefix . 'tube_saved_videos');
    }
}
