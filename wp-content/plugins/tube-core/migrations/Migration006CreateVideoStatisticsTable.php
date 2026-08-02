<?php
/**
 * Creates wp_tube_video_statistics.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Creates wp_tube_video_statistics — the read-optimized rollup table, per
 * ARCHITECTURE.md §2.3/§12 Phase 4. One row per video, recomputed
 * periodically by the `stats:rollup` WP-CLI job from wp_tube_video_views.
 *
 * `views_total` is maintained as a running counter, incremented at flush
 * time (see `VideoStatisticsRepository::bump_totals()`) rather than
 * recomputed by summing wp_tube_video_views — that table has a retention
 * window (`views:partition-maintenance`), so summing it can never
 * correctly reconstruct a true all-time total once old buckets have been
 * purged. `views_today`/`views_7d`/`views_30d` are always recomputed
 * fresh from wp_tube_video_views instead, since those windows are always
 * well within the retention period.
 *
 * Indexed on `views_total` and `views_7d` — the two columns ARCHITECTURE.md
 * §16 names explicitly ("purge 'trending'/'most viewed' listing keys"),
 * for the `ORDER BY ... LIMIT` queries a future "Most Viewed"/"Trending"
 * listing (tube-search, Phase 7) will run. `views_today`/`views_30d`
 * are not separately indexed — no documented consumer needs them sorted,
 * and an index with no real query behind it is exactly the kind of
 * unjustified cost this phase's "avoid enterprise patterns with no
 * measurable benefit at this scale" instruction rules out; add one if and
 * when a real query needs it.
 */
final class Migration006CreateVideoStatisticsTable extends AbstractMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '006';
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Create wp_tube_video_statistics (pre-aggregated view counts, §2.3).';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $wpdb            = $this->db();
        $charset_collate = $this->charset_collate();

        $this->apply_schema(
            "CREATE TABLE {$wpdb->prefix}tube_video_statistics (
                video_id BIGINT UNSIGNED NOT NULL,
                views_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
                views_today BIGINT UNSIGNED NOT NULL DEFAULT 0,
                views_7d BIGINT UNSIGNED NOT NULL DEFAULT 0,
                views_30d BIGINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (video_id),
                KEY views_total_idx (views_total),
                KEY views_7d_idx (views_7d)
            ) {$charset_collate};"
        );
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $this->drop_table($this->db()->prefix . 'tube_video_statistics');
    }
}
