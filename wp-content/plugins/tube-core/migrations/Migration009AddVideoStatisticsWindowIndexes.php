<?php
/**
 * Adds views_today_idx and views_30d_idx to wp_tube_video_statistics.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Adds views_today_idx and views_30d_idx to wp_tube_video_statistics.
 *
 * Found during Phase 11's SQL/index audit: `Migration006CreateVideoStatisticsTable`'s
 * own docblock deliberately left `views_today`/`views_30d` unindexed,
 * reasoning "no documented consumer needs them sorted... add one if and
 * when a real query needs it." That trigger has since fired —
 * `Tube_Admin\Statistics\StatisticsDashboardScreen::SORTABLE_COLUMNS`
 * (Phase 10) lets an admin sort the Statistics dashboard by all four
 * `views_*` columns, but `VideoStatisticsRepository::list_all()`'s
 * `ORDER BY {$order_by}` had no supporting index for two of them,
 * forcing a filesort. This is a purely additive, low-risk index
 * addition, applied via a new migration rather than editing
 * Migration006 (already applied — migrations are not rewritten after
 * the fact, same precedent as Migration004).
 */
final class Migration009AddVideoStatisticsWindowIndexes extends AbstractMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '009';
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Add views_today_idx and views_30d_idx to wp_tube_video_statistics (Phase 11 index audit).';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $wpdb            = $this->db();
        $charset_collate = $this->charset_collate();

        // dbDelta() diffs against the full table definition and adds
        // only what's missing (here, the two new indexes) — it never
        // touches existing rows or the columns/indexes already in place.
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
                KEY views_7d_idx (views_7d),
                KEY views_today_idx (views_today),
                KEY views_30d_idx (views_30d)
            ) {$charset_collate};"
        );
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $wpdb = $this->db();

        $this->drop_index($wpdb->prefix . 'tube_video_statistics', 'views_today_idx');
        $this->drop_index($wpdb->prefix . 'tube_video_statistics', 'views_30d_idx');
    }
}
