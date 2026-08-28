<?php
/**
 * Creates wp_tube_comment_likes.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Creates `wp_tube_comment_likes` — one row per (user, comment) like.
 *
 * A comment like is a separate domain object from a video like
 * (`wp_tube_video_likes` in tube-core) per Phase 16's explicit
 * instruction — different subject, different table, own counter.
 *
 * Only `user_id` (no `visitor_token` column, unlike
 * `wp_tube_video_likes`): commenting and comment-liking both require an
 * authenticated member (Phase 12), so there is no guest identity to
 * carry here. The `UNIQUE KEY` is what makes "one like per user/comment"
 * a real database guarantee against a concurrent double-tap, the same
 * `INSERT IGNORE`-against-a-UNIQUE-KEY technique
 * `Tube_Core\Likes\Repositories\LikeRepository::add()` already uses.
 */
final class Migration002CreateCommentLikesTable extends AbstractMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '002';
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Create wp_tube_comment_likes (one row per user/comment like).';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $table           = $this->db()->prefix . 'tube_comment_likes';
        $charset_collate = $this->charset_collate();

        $this->apply_schema(
            "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                comment_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY user_comment_idx (user_id, comment_id),
                KEY comment_id_idx (comment_id)
            ) {$charset_collate};"
        );
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $this->drop_table($this->db()->prefix . 'tube_comment_likes');
    }
}
