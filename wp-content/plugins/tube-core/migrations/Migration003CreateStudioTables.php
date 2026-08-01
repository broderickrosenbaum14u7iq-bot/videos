<?php
/**
 * Creates wp_tube_studios and wp_tube_video_studios.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Migrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Creates wp_tube_studios and wp_tube_video_studios.
 *
 * Dedicated tables for the studio relationship, for the same reasons as
 * wp_tube_actors (ARCHITECTURE.md §14). Studio remains hierarchical
 * (parent_id) to preserve the parent/sub-studio relationships it had as
 * a taxonomy. No foreign key on parent_id, for the same dbDelta/WordPress
 * convention reasons documented in Migration001CreateVideoMetadataTable.
 */
final class Migration003CreateStudioTables extends AbstractMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '003';
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Create wp_tube_studios and wp_tube_video_studios (supersedes a studio taxonomy, ARCHITECTURE.md §14).';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $wpdb            = $this->db();
        $charset_collate = $this->charset_collate();

        $this->apply_schema(
            "CREATE TABLE {$wpdb->prefix}tube_studios (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(191) NOT NULL,
                slug VARCHAR(191) NOT NULL,
                description TEXT,
                logo_image_id BIGINT UNSIGNED DEFAULT NULL,
                website_url VARCHAR(255) DEFAULT NULL,
                parent_id BIGINT UNSIGNED DEFAULT NULL,
                video_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY slug_idx (slug),
                KEY parent_id_idx (parent_id)
            ) {$charset_collate};"
        );

        $this->apply_schema(
            "CREATE TABLE {$wpdb->prefix}tube_video_studios (
                video_id BIGINT UNSIGNED NOT NULL,
                studio_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY  (video_id, studio_id),
                KEY studio_video_idx (studio_id, video_id)
            ) {$charset_collate};"
        );
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $wpdb = $this->db();

        $this->drop_table($wpdb->prefix . 'tube_video_studios');
        $this->drop_table($wpdb->prefix . 'tube_studios');
    }
}
