<?php
/**
 * Creates wp_tube_comments.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Creates `wp_tube_comments` — one row per video comment or reply.
 *
 * Custom table rather than `wp_comments` (see tube-comments' Plugin.php
 * docblock for the full storage-decision writeup): this table is
 * designed from the start for millions of rows across 100k+ videos, with
 * indexes shaped for the exact three read patterns the UI actually
 * needs (root-comment pages sorted by recency, root-comment pages sorted
 * by popularity, and a root comment's own replies) rather than
 * `wp_comments`'s generic `comment_post_ID`/`comment_parent` shape, which
 * has no index usable for a popularity sort and would require
 * `comment_type` discrimination on every single query to stay isolated
 * from unrelated WordPress comments.
 *
 * Replies are always stored with `parent_id` pointing at the ROOT
 * comment, never at an intermediate reply — Phase 15's "one visible
 * nested level only" is enforced at write time, not just render time, so
 * the reply-listing query (`parent_id = ? ORDER BY created_at`) never has
 * to recurse. A reply-to-a-reply instead carries `reply_to_user_id`, used
 * only to render an "@DisplayName" prefix.
 *
 * `content` stores plain, already-sanitized text (see
 * `Tube_Comments\Comments\ContentSanitizer`) — never HTML — so rendering
 * (timestamp links, URL links, emoji) happens at output time and can
 * change without a data migration.
 *
 * `likes_total`/`replies_total` are denormalized counters, the same
 * "counter column kept in sync by the service layer, never derived with
 * COUNT(*) per request" pattern `wp_tube_video_statistics.likes_total`
 * already establishes (see `Migration012AddLikesTotalToVideoStatistics`
 * in tube-core).
 */
final class Migration001CreateCommentsTable extends AbstractMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '001';
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Create wp_tube_comments (video comments and one-level replies).';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $table           = $this->db()->prefix . 'tube_comments';
        $charset_collate = $this->charset_collate();

        $this->apply_schema(
            "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                video_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                parent_id BIGINT UNSIGNED DEFAULT NULL,
                reply_to_user_id BIGINT UNSIGNED DEFAULT NULL,
                content TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'published',
                likes_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
                replies_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
                edited_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY video_root_recent_idx (video_id, parent_id, status, created_at),
                KEY video_root_popular_idx (video_id, parent_id, status, likes_total),
                KEY parent_idx (parent_id, status, created_at),
                KEY user_idx (user_id, created_at),
                KEY status_idx (status, created_at)
            ) {$charset_collate};"
        );
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $this->drop_table($this->db()->prefix . 'tube_comments');
    }
}
