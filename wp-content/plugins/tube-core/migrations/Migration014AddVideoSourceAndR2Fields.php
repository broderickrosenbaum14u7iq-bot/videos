<?php
/**
 * Adds a video-source abstraction to wp_tube_video_metadata (Cloudflare Stream + R2/direct MP4).
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Adds a `source` column and an `r2_object_key` column to
 * `wp_tube_video_metadata`, so a video's playable bytes can come from
 * Cloudflare Stream (existing, unchanged behavior) or directly from a
 * Cloudflare R2 bucket over HTTPS (new).
 *
 * `source` defaults to `'cloudflare_stream'` — every existing row is
 * backfilled to this value by the `ADD COLUMN ... DEFAULT` itself (a
 * metadata-only operation on MariaDB 11.4, not a data rewrite), so no
 * existing Stream video ever needs re-saving to keep playing.
 *
 * `cf_stream_uid` is widened from `NOT NULL` to nullable: an R2 video has
 * no Cloudflare Stream UID at all. The `cf_stream_uid_idx` UNIQUE KEY
 * still works correctly with multiple `NULL` values (standard MySQL/
 * MariaDB behavior — a UNIQUE index never treats two `NULL`s as
 * duplicates of each other).
 *
 * `r2_object_key` stores the canonical (already percent-decoded) object
 * key only — never a full URL (ARCHITECTURE.md §2.1's "no playback URL
 * ever persisted" rule extends to this second source unchanged); the
 * configured R2 base URL is applied at render time, not stored per row.
 *
 * No new `ENUM` column for R2 readiness: `cf_status` is reused as a
 * source-agnostic readiness signal for both sources — see
 * `Tube_Core\Video\VideoMetadata`'s own docblock for why.
 */
final class Migration014AddVideoSourceAndR2Fields extends AbstractMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '014';
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Add source/r2_object_key to wp_tube_video_metadata for Cloudflare R2/direct-MP4 video support.';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $table = $this->db()->prefix . 'tube_video_metadata';

        $this->modify_column($table, 'cf_stream_uid VARCHAR(64) NULL DEFAULT NULL');

        $charset_collate = $this->charset_collate();

        // dbDelta() diffs against this full table definition and adds
        // only what's missing (here, `source` and `r2_object_key`) — it
        // never touches columns/indexes already in place, including the
        // nullability change just applied above via modify_column().
        $this->apply_schema(
            "CREATE TABLE {$table} (
                video_id BIGINT UNSIGNED NOT NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'cloudflare_stream',
                cf_stream_uid VARCHAR(64) NULL DEFAULT NULL,
                r2_object_key VARCHAR(512) NULL DEFAULT NULL,
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
                UNIQUE KEY r2_object_key_idx (r2_object_key),
                KEY cf_status_idx (cf_status),
                KEY source_idx (source)
            ) {$charset_collate};"
        );
    }

    /**
     * {@inheritDoc}
     *
     * Reverting while any R2 video rows still exist (`cf_stream_uid IS
     * NULL`) is deliberately left to fail loudly on the final
     * modify_column() call below, rather than silently guessing a
     * placeholder UID for them — the same "know specifically what you're
     * accepting the loss of" posture `docs/ROLLBACK.md` §3 documents for
     * any schema rollback that isn't purely additive.
     */
    public function down(): void
    {
        $table = $this->db()->prefix . 'tube_video_metadata';

        $this->drop_index($table, 'source_idx');
        $this->drop_index($table, 'r2_object_key_idx');
        $this->drop_column($table, 'r2_object_key');
        $this->drop_column($table, 'source');
        $this->modify_column($table, 'cf_stream_uid VARCHAR(64) NOT NULL');
    }
}
