<?php
/**
 * Creates wp_tube_watch_history.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Creates wp_tube_watch_history — per-viewer progress, per
 * ARCHITECTURE.md §2.5/§12 Phase 5, §13's watch-history-scope open
 * decision now settled: both logged-in users (`user_id`) and guests
 * (`visitor_token`, a cookie-backed UUID — see `VisitorToken`) are
 * supported, never both set on the same row.
 *
 * Two separate `UNIQUE KEY`s — `(user_id, video_id)` and
 * `(visitor_token, video_id)` — are what "update existing instead of
 * duplicating" actually means at the database level:
 * `WatchHistoryRepository`'s upserts are a single
 * `INSERT ... ON DUPLICATE KEY UPDATE` against whichever key applies, so
 * a second progress update for the same viewer/video pair updates the
 * existing row instead of a loop-and-check application-level guard. This
 * relies on a real, load-bearing MySQL property: `UNIQUE KEY`s ignore
 * `NULL` (multiple rows may each have `NULL` in an indexed column
 * without violating uniqueness), so a guest row's `NULL` `user_id` never
 * collides with another guest's, and a logged-in row's `NULL`
 * `visitor_token` never collides with another user's.
 */
final class Migration008CreateWatchHistoryTable extends AbstractMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '008';
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Create wp_tube_watch_history (per-viewer progress, guest + logged-in, §2.5).';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $table           = $this->db()->prefix . 'tube_watch_history';
        $charset_collate = $this->charset_collate();

        $this->apply_schema(
            "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                video_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED DEFAULT NULL,
                visitor_token VARCHAR(64) DEFAULT NULL,
                progress_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                completed TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY user_video_idx (user_id, video_id),
                UNIQUE KEY visitor_video_idx (visitor_token, video_id),
                KEY video_id_idx (video_id),
                KEY updated_at_idx (updated_at)
            ) {$charset_collate};"
        );
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $this->drop_table($this->db()->prefix . 'tube_watch_history');
    }
}
