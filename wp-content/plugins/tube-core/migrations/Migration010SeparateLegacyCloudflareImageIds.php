<?php
/**
 * Separates legacy Cloudflare Images IDs from the now-WordPress-attachment-ID poster/OG columns.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Separates legacy Cloudflare Images IDs from the now-WordPress-attachment-ID poster/OG columns.
 *
 * Per ADR-0001 (`adr/0001-media-library-poster-images.md`), `wp_tube_video_metadata.poster_image_id`/
 * `og_image_id` now store WordPress Media Library attachment IDs instead
 * of Cloudflare Images IDs. Reinterpreting the existing columns in place
 * was explicitly rejected: this environment's own data was confirmed
 * `NULL` on every row before this migration was written (both columns
 * were never actually populated in any environment through v1.1.0 — see
 * the ADR), but the migration still does not assume that also holds for
 * an environment it cannot inspect. Instead of discarding or silently
 * reinterpreting whatever might be there:
 *
 * 1. The existing `poster_image_id`/`og_image_id` columns are renamed to
 *    `legacy_cf_poster_image_id`/`legacy_cf_og_image_id` — same data,
 *    same type, preserved and queryable, just parked under a name that
 *    makes clear it may hold a stale Cloudflare Images ID rather than a
 *    WordPress attachment ID.
 * 2. Fresh `poster_image_id`/`og_image_id` columns are added, starting
 *    `NULL` for every row — populated only through the new WordPress
 *    Media Library upload/picker flow going forward.
 *
 * The `legacy_cf_*` columns are not read by any application code after
 * this migration — deliberately: nothing in this codebase has a
 * legitimate reason to consume a Cloudflare Images ID once ADR-0001 is
 * in effect, so no repository/model method exposes them. They exist only
 * as a preserved, directly-SQL-queryable historical record.
 */
final class Migration010SeparateLegacyCloudflareImageIds extends AbstractMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '010';
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Rename poster_image_id/og_image_id to legacy_cf_*, add fresh WP-attachment-ID columns (ADR-0001).';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $table = $this->db()->prefix . 'tube_video_metadata';

        $this->rename_column($table, 'poster_image_id', 'legacy_cf_poster_image_id');
        $this->rename_column($table, 'og_image_id', 'legacy_cf_og_image_id');

        $charset_collate = $this->charset_collate();

        // dbDelta() diffs against this full table definition and adds
        // only what's missing (here, the two fresh columns) — it never
        // touches the columns/indexes already in place, including the
        // legacy_cf_* columns just renamed above.
        $this->apply_schema(
            "CREATE TABLE {$table} (
                video_id BIGINT UNSIGNED NOT NULL,
                cf_stream_uid VARCHAR(64) NOT NULL,
                cf_status ENUM('pending','processing','ready','error') NOT NULL DEFAULT 'pending',
                duration_seconds INT UNSIGNED DEFAULT NULL,
                thumbnail_time_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                legacy_cf_poster_image_id BIGINT UNSIGNED DEFAULT NULL,
                legacy_cf_og_image_id BIGINT UNSIGNED DEFAULT NULL,
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
        $table = $this->db()->prefix . 'tube_video_metadata';

        $this->drop_column($table, 'poster_image_id');
        $this->drop_column($table, 'og_image_id');
        $this->rename_column($table, 'legacy_cf_poster_image_id', 'poster_image_id');
        $this->rename_column($table, 'legacy_cf_og_image_id', 'og_image_id');
    }
}
