<?php
/**
 * Creates wp_tube_actors and wp_tube_video_actors.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Creates wp_tube_actors and wp_tube_video_actors.
 *
 * Dedicated tables for the actor relationship, chosen over a WordPress
 * taxonomy per ARCHITECTURE.md §14: actor is a high-cardinality,
 * many-per-video relationship — exactly the pattern that stresses
 * wp_term_relationships and WordPress's per-save term-count
 * recalculation most heavily. video_count on wp_tube_actors is
 * maintained explicitly by application code (tube-search, from Phase 7
 * onward), not recalculated automatically on every save the way
 * WordPress does for taxonomy terms.
 */
final class Migration002CreateActorTables extends AbstractMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '002';
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Create wp_tube_actors and wp_tube_video_actors (supersedes an actor taxonomy, ARCHITECTURE.md §14).';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $wpdb            = $this->db();
        $charset_collate = $this->charset_collate();

        $this->apply_schema(
            "CREATE TABLE {$wpdb->prefix}tube_actors (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(191) NOT NULL,
                slug VARCHAR(191) NOT NULL,
                bio TEXT,
                photo_image_id BIGINT UNSIGNED DEFAULT NULL,
                video_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY slug_idx (slug)
            ) {$charset_collate};"
        );

        $this->apply_schema(
            "CREATE TABLE {$wpdb->prefix}tube_video_actors (
                video_id BIGINT UNSIGNED NOT NULL,
                actor_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY  (video_id, actor_id),
                KEY actor_video_idx (actor_id, video_id)
            ) {$charset_collate};"
        );
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $wpdb = $this->db();

        // Relationship table first: no FK constraint enforces this order,
        // but it keeps wp_tube_actors from briefly being an orphaned
        // parent of a still-existing relationship table mid-rollback.
        $this->drop_table($wpdb->prefix . 'tube_video_actors');
        $this->drop_table($wpdb->prefix . 'tube_actors');
    }
}
