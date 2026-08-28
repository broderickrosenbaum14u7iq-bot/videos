<?php
/**
 * Creates wp_tube_comment_reports.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Creates `wp_tube_comment_reports` — one row per (reporter, comment)
 * report.
 *
 * The `UNIQUE KEY` on (reporter_user_id, comment_id) is what prevents one
 * account from generating unlimited duplicate reports against the same
 * comment (Phase 17) as a real database guarantee, not just an
 * application-level check — the same `INSERT IGNORE`-friendly shape used
 * throughout this project for "at most one row per (actor, subject)".
 *
 * A comment reaching moderation is a read-time decision (does this
 * comment have >= N distinct reports, or any report at all, depending on
 * the moderation screen's own filter) rather than a status this table
 * writes back onto `wp_tube_comments` directly — keeps report-recording
 * a pure append, with no read-modify-write race against the comment row.
 */
final class Migration003CreateCommentReportsTable extends AbstractMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '003';
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Create wp_tube_comment_reports (one row per reporter/comment report).';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $table           = $this->db()->prefix . 'tube_comment_reports';
        $charset_collate = $this->charset_collate();

        $this->apply_schema(
            "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                comment_id BIGINT UNSIGNED NOT NULL,
                reporter_user_id BIGINT UNSIGNED NOT NULL,
                reason VARCHAR(20) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY reporter_comment_idx (reporter_user_id, comment_id),
                KEY comment_id_idx (comment_id)
            ) {$charset_collate};"
        );
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $this->drop_table($this->db()->prefix . 'tube_comment_reports');
    }
}
