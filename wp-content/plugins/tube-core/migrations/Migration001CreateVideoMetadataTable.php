<?php
/**
 * Creates wp_tube_video_metadata.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Migrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Creates wp_tube_video_metadata.
 *
 * A typed 1:1 extension table for the `video` CPT (video_id is the
 * primary key), replacing wp_postmeta for video data entirely per
 * ARCHITECTURE.md §2.1. Stores only the Cloudflare Stream UID — never a
 * playback URL; tube-player constructs URLs from this UID at render
 * time. No foreign key constraints are declared: dbDelta() does not
 * support them reliably, and WordPress core itself never uses FKs on
 * wp_posts for the same reason — referential integrity is enforced at
 * the application layer, per standard WordPress convention.
 */
final class Migration001CreateVideoMetadataTable extends AbstractMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '001';
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Create wp_tube_video_metadata (Cloudflare Stream UID, status, duration, image refs).';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $table           = $this->db()->prefix . 'tube_video_metadata';
        $charset_collate = $this->charset_collate();

        $this->apply_schema(
            "CREATE TABLE {$table} (
                video_id BIGINT UNSIGNED NOT NULL,
                cf_stream_uid VARCHAR(64) NOT NULL,
                cf_status ENUM('pending','processing','ready','error') NOT NULL DEFAULT 'pending',
                duration_seconds INT UNSIGNED DEFAULT NULL,
                thumbnail_time_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                poster_image_id BIGINT UNSIGNED DEFAULT NULL,
                og_image_id BIGINT UNSIGNED DEFAULT NULL,
                schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (video_id),
                UNIQUE KEY cf_stream_uid_idx (cf_stream_uid),
                KEY cf_status_idx (cf_status)
            ) {$charset_collate};"
        );
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $this->drop_table($this->db()->prefix . 'tube_video_metadata');
    }
}
