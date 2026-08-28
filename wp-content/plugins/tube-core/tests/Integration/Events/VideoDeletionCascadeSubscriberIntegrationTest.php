<?php
/**
 * Integration tests for VideoDeletionCascadeSubscriber, against a real database.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Integration\Events;

use PHPUnit\Framework\TestCase;
use Tube_Core\Likes\Repositories\LikeRepository;
use Tube_Core\Saves\Repositories\SavedVideoRepository;
use Tube_Core\Video\CfStreamStatus;
use Tube_Core\Video\Repositories\VideoMetadataRepository;
use Tube_Core\Views\Repositories\VideoStatisticsRepository;
use Tube_Core\Views\Repositories\VideoViewsRepository;

/**
 * Release-audit CRIT-3 regression test: permanently deleting a video must
 * leave zero rows behind in any of tube-core's own five video-scoped
 * tables. Runs against the real `wp_delete_post()` → `before_delete_post`
 * → `VIDEO_DELETED` → `VideoDeletionCascadeSubscriber` path (the
 * subscriber is registered for real by `Tube_Core\Plugin::boot()`, which
 * has already run by the time this integration suite boots), not a
 * direct call to the subscriber's handler — proving the real wiring, not
 * just the cleanup logic in isolation.
 */
final class VideoDeletionCascadeSubscriberIntegrationTest extends TestCase
{
    /**
     * A real video post ID created for the test.
     *
     * @var int
     */
    private int $video_id;

    /**
     * Create a real video post for each test.
     */
    protected function setUp(): void
    {
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
     * Deleting a video with a row in every one of tube-core's five
     * video-scoped tables leaves zero rows behind in any of them.
     */
    public function test_permanent_delete_removes_rows_from_every_owned_table(): void
    {
        (new VideoMetadataRepository())->create($this->video_id, 'uid-' . uniqid('', true), CfStreamStatus::Ready);
        (new VideoStatisticsRepository())->ensure_baseline($this->video_id, 1000);
        (new VideoViewsRepository())->bulk_record([$this->video_id => 5], gmdate('Y-m-d H:00:00'));
        (new LikeRepository())->add(null, 'visitor-' . uniqid('', true), $this->video_id);
        (new SavedVideoRepository())->add(null, 'visitor-' . uniqid('', true), $this->video_id);

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $tables = [
            $wpdb->prefix . 'tube_video_metadata',
            $wpdb->prefix . 'tube_video_statistics',
            $wpdb->prefix . 'tube_video_views',
            $wpdb->prefix . 'tube_video_likes',
            $wpdb->prefix . 'tube_saved_videos',
        ];

        foreach ($tables as $table) {
            $message = "Sanity check: {$table} should have exactly one row before deletion.";
            self::assertSame(1, $this->row_count($wpdb, $table), $message);
        }

        wp_delete_post($this->video_id, true);

        foreach ($tables as $table) {
            $message = "{$table} must have zero rows left for a permanently-deleted video.";
            self::assertSame(0, $this->row_count($wpdb, $table), $message);
        }
    }

    /**
     * Row count for one video_id in one table.
     *
     * @param \wpdb  $wpdb  The global $wpdb instance.
     * @param string $table Fully-prefixed table name.
     */
    private function row_count(\wpdb $wpdb, string $table): int
    {
        $sql = $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE video_id = %d', $table, $this->video_id);

        self::assertNotNull($sql);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test assertion against a dedicated custom table; $sql *is* $wpdb->prepare()'d above.
        return (int) $wpdb->get_var($sql);
    }
}
