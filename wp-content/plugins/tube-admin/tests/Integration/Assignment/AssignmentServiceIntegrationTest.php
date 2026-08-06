<?php
/**
 * Integration tests for AssignmentService against real tube-core infrastructure.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Tests\Integration\Assignment;

use PHPUnit\Framework\TestCase;
use Tube_Admin\Assignment\AssignmentService;
use Tube_Core\Events\EventCatalog;
use Tube_Core\Plugin as Tube_Core_Plugin;

/**
 * Exercises AssignmentService against tube-core's real
 * ActorRepository/StudioRepository/Dispatcher — the write-then-notify
 * sequence can't be unit-tested with fakes (constructing a fake against
 * tube-core's interfaces would require a Composer-level dependency on
 * tube-core's package, which this project's plugin-independence
 * convention deliberately avoids; see AssignmentService's own docblock).
 * Confirms both the real database write and the real VIDEO_UPDATED
 * dispatch, which is what tube-search's/tube-cache's own event
 * subscribers depend on to stay in sync after an assignment change.
 */
final class AssignmentServiceIntegrationTest extends TestCase
{
    /**
     * Actor IDs created by a test, cleaned up in tearDown().
     *
     * @var int[]
     */
    private array $created_actor_ids = [];

    /**
     * Video posts created by a test, cleaned up in tearDown().
     *
     * @var int[]
     */
    private array $created_video_ids = [];

    /**
     * Delete every row created by the test.
     */
    protected function tearDown(): void
    {
        foreach ($this->created_actor_ids as $actor_id) {
            global $wpdb;
            /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup against a dedicated custom table.
            $wpdb->delete($wpdb->prefix . 'tube_video_actors', ['actor_id' => $actor_id], ['%d']);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup against a dedicated custom table.
            $wpdb->delete($wpdb->prefix . 'tube_actors', ['id' => $actor_id], ['%d']);
        }

        foreach ($this->created_video_ids as $video_id) {
            wp_delete_post($video_id, true);
        }

        $this->created_actor_ids = [];
        $this->created_video_ids = [];
    }

    /**
     * Set_actors_for_video() writes the real relationship row and dispatches a real VIDEO_UPDATED event.
     */
    public function test_set_actors_for_video_writes_and_dispatches_real_event(): void
    {
        $actor_id = $this->create_actor('Assignment Service Actor');
        $video_id = $this->create_video();

        $service = new AssignmentService(
            Tube_Core_Plugin::instance()->actor_repository(),
            Tube_Core_Plugin::instance()->studio_repository(),
            Tube_Core_Plugin::instance()->events()
        );

        $captured = [];
        Tube_Core_Plugin::instance()->events()->listen(
            EventCatalog::VIDEO_UPDATED,
            static function (array $payload) use (&$captured): void {
                $captured[] = $payload;
            }
        );

        $service->set_actors_for_video($video_id, [$actor_id]);

        self::assertSame(
            [$actor_id],
            Tube_Core_Plugin::instance()->actor_repository()->actor_ids_for_video($video_id)
        );
        self::assertSame([['video_id' => $video_id]], $captured);
    }

    /**
     * Bulk_add_actors() dispatches VIDEO_UPDATED once per affected video, backed by real writes.
     */
    public function test_bulk_add_actors_writes_and_dispatches_per_video(): void
    {
        $actor_id = $this->create_actor('Bulk Assignment Service Actor');
        $video_1  = $this->create_video();
        $video_2  = $this->create_video();

        $service = new AssignmentService(
            Tube_Core_Plugin::instance()->actor_repository(),
            Tube_Core_Plugin::instance()->studio_repository(),
            Tube_Core_Plugin::instance()->events()
        );

        $captured = [];
        Tube_Core_Plugin::instance()->events()->listen(
            EventCatalog::VIDEO_UPDATED,
            static function (array $payload) use (&$captured): void {
                $captured[] = $payload;
            }
        );

        $inserted = $service->bulk_add_actors([$video_1, $video_2], [$actor_id]);

        self::assertSame(2, $inserted);
        self::assertSame(2, Tube_Core_Plugin::instance()->actor_repository()->count_videos_for_actor($actor_id));
        self::assertSame(
            [$video_1, $video_2],
            array_column($captured, 'video_id')
        );
    }

    /**
     * Create a real actor row via the real repository's own create()
     * method (Phase 10 write API), tracked for teardown.
     *
     * @param string $name The actor's name.
     */
    private function create_actor(string $name): int
    {
        $actor_id = Tube_Core_Plugin::instance()->actor_repository()->create($name, null);

        $this->created_actor_ids[] = $actor_id;

        return $actor_id;
    }

    /**
     * Create a real published video post, tracked for teardown.
     */
    private function create_video(): int
    {
        $video_id = wp_insert_post(
            [
                'post_type'   => 'video',
                'post_title'  => 'Assignment Service Integration Test Video',
                'post_status' => 'publish',
            ],
            true
        );

        self::assertIsInt($video_id);
        $this->created_video_ids[] = $video_id;

        return $video_id;
    }
}
