<?php
/**
 * Adds likes_total to wp_tube_video_statistics.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Adds `likes_total` to `wp_tube_video_statistics` — a running per-video
 * like counter, the same "one per-video aggregate row, several counter
 * columns" shape `views_total`/`views_today`/`views_7d`/`views_30d`
 * already establish on this exact table, rather than a new one-column
 * table of its own. Atomically incremented/decremented by
 * `VideoStatisticsRepository::increment_likes()`/`decrement_likes()`
 * (`Tube_Core\Likes\LikeToggleService` calls these only after confirming
 * a real row was actually inserted/deleted in `wp_tube_video_likes`, so
 * this counter can never drift from that table's true row count under a
 * concurrent-toggle race).
 *
 * A purely additive column via dbDelta() — re-issues the full current
 * table definition (`Migration009AddVideoStatisticsWindowIndexes`'s own
 * shape) with `likes_total` appended, the same "re-issue the full CREATE
 * TABLE, dbDelta() diffs and adds only what's missing" pattern every
 * additive schema change in this project uses.
 */
final class Migration012AddLikesTotalToVideoStatistics extends AbstractMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '012';
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Add likes_total to wp_tube_video_statistics (real Like system).';
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
                likes_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
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
        $this->drop_column($this->db()->prefix . 'tube_video_statistics', 'likes_total');
    }
}
