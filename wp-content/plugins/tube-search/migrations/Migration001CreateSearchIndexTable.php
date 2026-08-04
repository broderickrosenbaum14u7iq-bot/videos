<?php
/**
 * Creates wp_tube_search_index.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Creates wp_tube_search_index — the denormalized search/listing index,
 * per ARCHITECTURE.md §2.6/§12 Phase 7. Schema matches §2.6 exactly.
 *
 * Owned by tube-search, not tube-core — populated by copying
 * (denormalizing) data from the CPT, taxonomies, and tube-core's
 * `wp_tube_video_metadata`/`wp_tube_video_statistics`, never by a live
 * join at query time. Kept in sync incrementally (event-driven upsert,
 * `Tube_Search\Events\SearchIndexSyncSubscriber`) and in bulk
 * (`wp tube-search index:rebuild`, `Tube_Search\CLI\IndexCommand`).
 *
 * Extends tube-core's `AbstractMigration` directly — safe despite
 * tube-search's own composer.json having no dependency on tube-core's
 * package (DEVELOPMENT_RULES.md §2's plugin-independence rule): this
 * class is never loaded by tube-search's own standalone PHPUnit suite
 * (migrations are verified live, the same as every migration in this
 * project), only inside real WordPress, where `Requires Plugins:
 * tube-core` in `tube-search.php`'s header already guarantees tube-core
 * is active and loaded first.
 */
final class Migration001CreateSearchIndexTable extends AbstractMigration
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
        return 'Create wp_tube_search_index (denormalized search/listing index, §2.6).';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $table           = $this->db()->prefix . 'tube_search_index';
        $charset_collate = $this->charset_collate();

        $this->apply_schema(
            "CREATE TABLE {$table} (
                video_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                category_ids VARCHAR(255) NULL,
                tag_ids VARCHAR(255) NULL,
                actor_ids VARCHAR(255) NULL,
                studio_ids VARCHAR(255) NULL,
                duration_seconds INT UNSIGNED NULL,
                views_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
                published_at DATETIME NULL,
                indexed_at DATETIME NOT NULL,
                PRIMARY KEY  (video_id),
                FULLTEXT KEY search_text_idx (title, description),
                KEY published_idx (published_at),
                KEY views_total_idx (views_total)
            ) {$charset_collate};"
        );
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $this->drop_table($this->db()->prefix . 'tube_search_index');
    }
}
