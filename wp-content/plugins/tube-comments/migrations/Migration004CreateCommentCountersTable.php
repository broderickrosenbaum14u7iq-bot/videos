<?php
/**
 * Creates wp_tube_comment_counters.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Creates `wp_tube_comment_counters` — one row per video, holding the
 * "💬 Bình luận N" count (Phase 25).
 *
 * A dedicated one-column-of-substance table owned entirely by
 * tube-comments, rather than adding a `comments_total` column onto
 * tube-core's `wp_tube_video_statistics`: tube-comments MAY depend on
 * tube-core at runtime (reusing `AbstractMigration`,
 * `Tube_Core\Support\RedisRateLimiter`, `Tube_Core\WatchHistory\VisitorToken`
 * where relevant), but a comment count is tube-comments' own concern —
 * owning its own counter table avoids ALTERing another plugin's table
 * and avoids adding new methods to
 * `Tube_Core\Views\Repositories\VideoStatisticsRepositoryInterface`
 * (a protected file) merely to serve this plugin, keeping tube-comments
 * fully removable with zero trace left in tube-core's schema.
 *
 * The count policy (Phase 25's required documentation): `comments_total`
 * counts PUBLISHED root comments AND published replies together — every
 * comment a viewer can actually read counts toward the badge, since the
 * badge is a "how much discussion is here" signal, not a "how many
 * top-level threads" signal. Pending/spam/deleted comments never count.
 * Maintained by `Tube_Comments\Comments\CommentService` incrementing on
 * publish and decrementing on delete/unpublish — never a `COUNT(*)` over
 * `wp_tube_comments` at render time.
 */
final class Migration004CreateCommentCountersTable extends AbstractMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '004';
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Create wp_tube_comment_counters (one row per video, comments_total).';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $table           = $this->db()->prefix . 'tube_comment_counters';
        $charset_collate = $this->charset_collate();

        $this->apply_schema(
            "CREATE TABLE {$table} (
                video_id BIGINT UNSIGNED NOT NULL,
                comments_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (video_id)
            ) {$charset_collate};"
        );
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $this->drop_table($this->db()->prefix . 'tube_comment_counters');
    }
}
