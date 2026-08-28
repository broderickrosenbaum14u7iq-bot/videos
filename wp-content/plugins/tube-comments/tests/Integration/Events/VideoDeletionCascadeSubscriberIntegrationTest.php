<?php
/**
 * Integration tests for VideoDeletionCascadeSubscriber, against a real database.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Tests\Integration\Events;

use PHPUnit\Framework\TestCase;
use Tube_Comments\Comments\Repositories\CommentCounterRepository;
use Tube_Comments\Comments\Repositories\CommentLikeRepository;
use Tube_Comments\Comments\Repositories\CommentReportRepository;
use Tube_Comments\Comments\Repositories\CommentRepository;
use Tube_Comments\Comments\Repositories\CommentRootLockRepository;

/**
 * Release-audit CRIT-3 regression test: permanently deleting a video must
 * leave zero rows behind in any of this plugin's five video/comment-scoped
 * tables, including the two (`wp_tube_comment_likes`/`_reports`) that are
 * only reachable via a comment_id, not a direct video_id column. Runs
 * against the real `wp_delete_post()` → tube-core's `before_delete_post` →
 * `VIDEO_DELETED` → `VideoDeletionCascadeSubscriber` path (the subscriber
 * is registered for real by `Tube_Comments\Plugin::boot()`, which has
 * already run by the time this integration suite boots), not a direct
 * call to the subscriber's handler.
 */
final class VideoDeletionCascadeSubscriberIntegrationTest extends TestCase
{
    /**
     * A real WP_User created for the test.
     *
     * @var int
     */
    private int $user_id;

    /**
     * A real video post ID created for the test.
     *
     * @var int
     */
    private int $video_id;

    /**
     * Build a real member and a real video for each test.
     */
    protected function setUp(): void
    {
        $user_id = wp_insert_user(
            [
                'user_login' => 'cascade-it-' . uniqid('', true),
                'user_email' => uniqid('cascade-it-', true) . '@example.com',
                'user_pass'  => wp_generate_password(),
                'role'       => 'subscriber',
            ]
        );

        self::assertIsInt($user_id);
        $this->user_id = $user_id;

        $video_id = wp_insert_post(
            [
                'post_type'   => 'video',
                'post_title'  => 'VideoDeletionCascadeSubscriber Integration Test Video',
                'post_status' => 'publish',
            ],
            true
        );

        self::assertIsInt($video_id);
        $this->video_id = $video_id;
    }

    /**
     * Delete the member (the video is expected to already be gone by the
     * time each test finishes).
     */
    protected function tearDown(): void
    {
        wp_delete_user($this->user_id);
    }

    /**
     * Deleting a video with a root comment, a reply, a like on each, a
     * report, a counter row, and a root lock leaves zero rows behind in
     * any of the five tables — including the comment-ID-keyed likes/
     * reports tables, which have no video_id column of their own.
     */
    public function test_permanent_delete_removes_rows_from_every_owned_table(): void
    {
        $comment_repository = new CommentRepository();

        $root_id = $comment_repository->insert(
            [
                'video_id'         => $this->video_id,
                'user_id'          => $this->user_id,
                'parent_id'        => null,
                'reply_to_user_id' => null,
                'content'          => 'Root comment for cascade-delete test.',
                'status'           => 'visible',
            ]
        );

        $reply_id = $comment_repository->insert(
            [
                'video_id'         => $this->video_id,
                'user_id'          => $this->user_id,
                'parent_id'        => $root_id,
                'reply_to_user_id' => $this->user_id,
                'content'          => 'Reply for cascade-delete test.',
                'status'           => 'visible',
            ]
        );

        (new CommentLikeRepository())->add($this->user_id, $root_id);
        (new CommentReportRepository())->add($reply_id, $this->user_id, 'spam');
        (new CommentCounterRepository())->increment($this->video_id);
        (new CommentRootLockRepository())->try_acquire($this->user_id, $this->video_id, 86400);

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $comments      = $wpdb->prefix . 'tube_comments';
        $comment_likes = $wpdb->prefix . 'tube_comment_likes';
        $reports       = $wpdb->prefix . 'tube_comment_reports';
        $counters      = $wpdb->prefix . 'tube_comment_counters';
        $root_locks    = $wpdb->prefix . 'tube_comment_root_locks';

        self::assertSame(2, $this->count_by_video_id($wpdb, $comments, $this->video_id));
        self::assertSame(1, $this->count_by_comment_id($wpdb, $comment_likes, $root_id));
        self::assertSame(1, $this->count_by_comment_id($wpdb, $reports, $reply_id));
        self::assertSame(1, $this->count_by_video_id($wpdb, $counters, $this->video_id));
        self::assertSame(1, $this->count_by_video_id($wpdb, $root_locks, $this->video_id));

        wp_delete_post($this->video_id, true);

        self::assertSame(0, $this->count_by_video_id($wpdb, $comments, $this->video_id));
        self::assertSame(0, $this->count_by_comment_id($wpdb, $comment_likes, $root_id));
        self::assertSame(0, $this->count_by_comment_id($wpdb, $reports, $reply_id));
        self::assertSame(0, $this->count_by_video_id($wpdb, $counters, $this->video_id));
        self::assertSame(0, $this->count_by_video_id($wpdb, $root_locks, $this->video_id));
    }

    /**
     * Row count matching one table's `video_id` column.
     *
     * @param \wpdb  $wpdb     The global $wpdb instance.
     * @param string $table    Fully-prefixed table name.
     * @param int    $video_id The value to match.
     */
    private function count_by_video_id(\wpdb $wpdb, string $table, int $video_id): int
    {
        $sql = $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE video_id = %d', $table, $video_id);

        self::assertNotNull($sql);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test assertion against a dedicated custom table; $sql *is* $wpdb->prepare()'d above.
        return (int) $wpdb->get_var($sql);
    }

    /**
     * Row count matching one table's `comment_id` column.
     *
     * @param \wpdb  $wpdb       The global $wpdb instance.
     * @param string $table      Fully-prefixed table name.
     * @param int    $comment_id The value to match.
     */
    private function count_by_comment_id(\wpdb $wpdb, string $table, int $comment_id): int
    {
        $sql = $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE comment_id = %d', $table, $comment_id);

        self::assertNotNull($sql);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test assertion against a dedicated custom table; $sql *is* $wpdb->prepare()'d above.
        return (int) $wpdb->get_var($sql);
    }
}
