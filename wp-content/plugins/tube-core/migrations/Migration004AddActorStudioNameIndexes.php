<?php
/**
 * Adds name_idx to wp_tube_actors and wp_tube_studios.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Adds name_idx to wp_tube_actors and wp_tube_studios.
 *
 * Found during the Phase 1 audit: ARCHITECTURE.md §10 requires
 * "AJAX-searchable term pickers" for actor/studio at scale, but neither
 * table had an index on `name` — only on `slug`, which doesn't serve a
 * search-as-you-type lookup. This is a purely additive, low-risk index
 * addition, applied via a new migration rather than editing
 * Migration002CreateActorTables/Migration003CreateStudioTables, since
 * both were already applied — migrations are not rewritten after the
 * fact.
 */
final class Migration004AddActorStudioNameIndexes extends AbstractMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '004';
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Add name_idx to wp_tube_actors and wp_tube_studios for AJAX-searchable admin pickers (§10).';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $wpdb            = $this->db();
        $charset_collate = $this->charset_collate();

        // dbDelta() diffs against the full table definition and adds
        // only what's missing (here, name_idx) — it never touches
        // existing rows or the columns/indexes already in place.
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
                UNIQUE KEY slug_idx (slug),
                KEY name_idx (name)
            ) {$charset_collate};"
        );

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
                KEY parent_id_idx (parent_id),
                KEY name_idx (name)
            ) {$charset_collate};"
        );
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $wpdb = $this->db();

        $this->drop_index($wpdb->prefix . 'tube_actors', 'name_idx');
        $this->drop_index($wpdb->prefix . 'tube_studios', 'name_idx');
    }
}
