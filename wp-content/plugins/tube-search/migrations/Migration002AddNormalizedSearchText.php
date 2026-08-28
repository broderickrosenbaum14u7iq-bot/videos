<?php
/**
 * Adds wp_tube_search_index.search_text_normalized and its FULLTEXT index.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Adds `wp_tube_search_index.search_text_normalized` (accent-folded,
 * lowercased, whitespace-normalized title+description —
 * `Tube_Search\Search\TextNormalizer::normalize()`) and a `FULLTEXT` index
 * on it, replacing `Migration001`'s `search_text_idx (title, description)` —
 * see that class's own docblock for why matching against raw
 * `title`/`description` was asymmetric for accented queries (MySQL
 * collation folds some accent differences but not Vietnamese "Đ"/"đ",
 * which has no Unicode decomposition to fold via collation at all) and
 * why matching a normalized column instead removes that dependency
 * entirely, for both the query and the indexed side alike.
 *
 * `search_text_normalized` starts `NULL` for every existing row — no
 * data backfill happens here (this project's established "migrations own
 * schema, not data" split — see `ARCHITECTURE-CHANGELOG.md`'s 2026-08-24
 * ADR-0001 entry for the same reasoning applied to a schema-only
 * migration). `wp tube-search index:rebuild` (already the normal,
 * existing command for populating this table) is what actually computes
 * and writes real values, through `SearchIndexRepository::upsert()` —
 * the exact same path an incremental sync already uses, so a rebuild and
 * an incremental resync can never disagree on what the normalized value
 * for a given title should be.
 */
final class Migration002AddNormalizedSearchText extends AbstractMigration
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
        return 'Add wp_tube_search_index.search_text_normalized + FULLTEXT index, replacing the raw'
            . ' title/description FULLTEXT index.';
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
                search_text_normalized TEXT NULL,
                PRIMARY KEY  (video_id),
                FULLTEXT KEY search_text_normalized_idx (search_text_normalized),
                KEY published_idx (published_at),
                KEY views_total_idx (views_total)
            ) {$charset_collate};"
        );

        // dbDelta() only ever adds columns/indexes when diffing a CREATE
        // TABLE statement — it never removes what's no longer listed, so
        // the superseded FULLTEXT(title, description) index from
        // Migration001 (no longer read by anything now that self::search()
        // matches search_text_normalized instead) is dropped explicitly.
        $this->drop_index($table, 'search_text_idx');
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $table = $this->db()->prefix . 'tube_search_index';

        $this->drop_index($table, 'search_text_normalized_idx');
        $this->drop_column($table, 'search_text_normalized');

        // Restore Migration001's original FULLTEXT(title, description) index.
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
            ) {$this->charset_collate()};"
        );
    }
}
