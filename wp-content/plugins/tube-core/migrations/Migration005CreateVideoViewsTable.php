<?php
/**
 * Creates wp_tube_video_views.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Creates wp_tube_video_views — the write-optimized, hourly-bucketed view
 * ingest table, per ARCHITECTURE.md §2.2/§12 Phase 4.
 *
 * One row per (video, hour-it-was-viewed-in), incremented in place rather
 * than one row per view — this is what "hourly-bucketed counters" means:
 * even at several million pageviews/month, the row count this table
 * actually grows by is bounded by *distinct (video, hour) combinations
 * with at least one view*, not by raw view count.
 *
 * ARCHITECTURE.md §2.2 additionally describes "monthly partitions" for
 * this table (unchanged wording carried from an earlier revision). Not
 * implemented as MySQL native `PARTITION BY` here — deliberately, per
 * this phase's explicit instruction to size the implementation for this
 * project's real target (3,000–10,000 videos, a few million
 * pageviews/month, one VPS) and avoid patterns that only pay off at the
 * 500,000+-video ceiling the original architecture text was written
 * against. At the real target, even several years of retained hourly
 * buckets stays in the low tens of millions of rows on a single indexed
 * InnoDB table — well within what a plain table handles without
 * partitioning's real costs (migration complexity, `dbDelta()` has no
 * partition-management support, operational complexity for a one-person/
 * small-team VPS deploy). Retention is still real (see
 * `views:partition-maintenance`, Migration/Repositories built in this
 * phase) — it just doesn't need `DROP PARTITION` to be fast at this
 * scale; a plain indexed `DELETE ... WHERE view_hour < cutoff` is.
 */
final class Migration005CreateVideoViewsTable extends AbstractMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '005';
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Create wp_tube_video_views (hourly-bucketed view counters, Redis-buffered writes, §2.2).';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $wpdb            = $this->db();
        $charset_collate = $this->charset_collate();

        $this->apply_schema(
            "CREATE TABLE {$wpdb->prefix}tube_video_views (
                video_id BIGINT UNSIGNED NOT NULL,
                view_hour DATETIME NOT NULL,
                view_count INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY  (video_id, view_hour),
                KEY view_hour_idx (view_hour)
            ) {$charset_collate};"
        );
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $this->drop_table($this->db()->prefix . 'tube_video_views');
    }
}
