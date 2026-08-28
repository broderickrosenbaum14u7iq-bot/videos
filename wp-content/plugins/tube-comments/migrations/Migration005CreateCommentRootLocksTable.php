<?php
/**
 * Creates wp_tube_comment_root_locks.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Creates `wp_tube_comment_root_locks` — the race-safe slot table behind
 * "at most one root comment per video per rolling 24-hour window" (see
 * `Tube_Comments\Comments\Repositories\CommentRootLockRepository`'s own
 * docblock for the full concurrency-design writeup).
 *
 * `PRIMARY KEY (user_id, video_id)` is deliberately the ONLY index: this
 * table is read/written exclusively by primary-key lookup
 * (`WHERE user_id = ? AND video_id = ?`) from exactly two call sites, so
 * a secondary index would add write cost for zero query benefit. One row
 * per (user, video) pair regardless of how many root comments that pair
 * has produced over the table's lifetime — this table's total size scales
 * with active (user, video) pairs, not with total comment volume, and a
 * row is silently reused (its `created_at` overwritten) the next time
 * that pair's 24-hour window has elapsed, so it never grows unbounded
 * the way an append-only log would.
 */
final class Migration005CreateCommentRootLocksTable extends AbstractMigration
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
        return 'Create wp_tube_comment_root_locks (race-safe one-root-comment-per-video-per-24h slot).';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $table           = $this->db()->prefix . 'tube_comment_root_locks';
        $charset_collate = $this->charset_collate();

        $this->apply_schema(
            "CREATE TABLE {$table} (
                user_id BIGINT UNSIGNED NOT NULL,
                video_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (user_id, video_id)
            ) {$charset_collate};"
        );
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $this->drop_table($this->db()->prefix . 'tube_comment_root_locks');
    }
}
