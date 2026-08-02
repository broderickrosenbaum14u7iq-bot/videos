<?php
/**
 * Integration tests for watch history against a real database and,
 * for the REST layer, the real REST API.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Integration\WatchHistory;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tube_Core\WatchHistory\Repositories\WatchHistoryRepository;
use WP_REST_Request;

/**
 * Exercises WatchHistoryRepository directly against
 * wp_tube_watch_history, and the `POST
 * /tube/v1/videos/{id}/watch-history` route end-to-end through the real
 * REST server, for both a logged-in user and a guest.
 */
final class WatchHistoryIntegrationTest extends TestCase
{
    /**
     * The repository under test.
     *
     * @var WatchHistoryRepository
     */
    private WatchHistoryRepository $repository;

    /**
     * A real published video created for the test.
     *
     * @var int
     */
    private int $video_id;

    /**
     * A real user created for the test, or null if not yet created.
     *
     * @var int|null
     */
    private ?int $user_id = null;

    /**
     * Build a real repository and a real published video for each test.
     */
    protected function setUp(): void
    {
        $this->repository = new WatchHistoryRepository();

        $video_id = wp_insert_post(
            [
                'post_type'   => 'video',
                'post_title'  => 'Watch History Integration Test Video',
                'post_status' => 'publish',
            ],
            true
        );

        self::assertIsInt($video_id);
        $this->video_id = $video_id;
    }

    /**
     * Delete the video, any user created, and every watch-history row this test touched.
     *
     * @throws RuntimeException If the query template is malformed (a bug in this method, not in any argument).
     */
    protected function tearDown(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $sql = $wpdb->prepare(
            'DELETE FROM %i WHERE video_id = %d',
            $wpdb->prefix . 'tube_watch_history',
            $this->video_id
        );

        if (null === $sql) {
            throw new RuntimeException(
                'wpdb::prepare() returned null for the watch-history cleanup query in ' . self::class . '.'
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test cleanup against a dedicated custom table; $sql *is* $wpdb->prepare()'d above.
        $wpdb->query($sql);

        wp_delete_post($this->video_id, true);

        if (null !== $this->user_id) {
            wp_delete_user($this->user_id);
        }

        wp_set_current_user(0);
        unset($_COOKIE['tube_visitor']);
    }

    /**
     * Count wp_tube_watch_history rows for a given user_id.
     *
     * @param int $user_id The user to count rows for.
     */
    private function count_rows_for_user(int $user_id): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion against a dedicated custom table.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS row_count, MAX(progress_seconds) AS progress_seconds, MAX(completed) AS completed'
                . ' FROM %i WHERE user_id = %d AND video_id = %d',
                $wpdb->prefix . 'tube_watch_history',
                $user_id,
                $this->video_id
            ),
            ARRAY_A
        );

        self::assertIsArray($row);
        /** @var array{row_count: string} $row */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        return (int) $row['row_count'];
    }

    /**
     * Read back the single row for a given visitor_token.
     *
     * @param string $visitor_token The guest's token.
     *
     * @return array{row_count: int, progress_seconds: int}
     */
    private function read_guest_row(string $visitor_token): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion against a dedicated custom table.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS row_count, MAX(progress_seconds) AS progress_seconds'
                . ' FROM %i WHERE visitor_token = %s AND video_id = %d',
                $wpdb->prefix . 'tube_watch_history',
                $visitor_token,
                $this->video_id
            ),
            ARRAY_A
        );

        self::assertIsArray($row);
        /** @var array{row_count: string, progress_seconds: string|null} $row */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        return [
            'row_count'        => (int) $row['row_count'],
            'progress_seconds' => (int) $row['progress_seconds'],
        ];
    }

    /**
     * Repeated upsert_for_user() calls for the same user/video update the
     * one existing row instead of creating duplicates.
     *
     * @throws RuntimeException If the cleanup query template is malformed (a bug in this test, not in any argument).
     */
    public function test_repository_upsert_for_user_does_not_create_duplicate_rows(): void
    {
        $user_id = 900000001 + random_int(0, 100000);

        $this->repository->upsert_for_user($user_id, $this->video_id, 30, false);
        $this->repository->upsert_for_user($user_id, $this->video_id, 90, true);

        self::assertSame(1, $this->count_rows_for_user($user_id));

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $sql = $wpdb->prepare(
            'DELETE FROM %i WHERE user_id = %d',
            $wpdb->prefix . 'tube_watch_history',
            $user_id
        );

        if (null === $sql) {
            throw new RuntimeException(
                'wpdb::prepare() returned null for the user cleanup query in ' . self::class . '.'
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test cleanup against a dedicated custom table; $sql *is* $wpdb->prepare()'d above.
        $wpdb->query($sql);
    }

    /**
     * Repeated upsert_for_guest() calls for the same visitor_token/video
     * update the one existing row instead of creating duplicates.
     */
    public function test_repository_upsert_for_guest_does_not_create_duplicate_rows(): void
    {
        $visitor_token = wp_generate_uuid4();

        $this->repository->upsert_for_guest($visitor_token, $this->video_id, 15, false);
        $this->repository->upsert_for_guest($visitor_token, $this->video_id, 200, true);

        $row = $this->read_guest_row($visitor_token);

        self::assertSame(1, $row['row_count']);
        self::assertSame(200, $row['progress_seconds']);
    }

    /**
     * The REST endpoint records progress for a logged-in user against
     * their own user_id, deduplicating repeated calls the same way the
     * repository does directly.
     */
    public function test_rest_endpoint_records_progress_for_logged_in_user(): void
    {
        $user_id = wp_insert_user(
            [
                'user_login' => 'watchhistory-test-' . uniqid('', true),
                'user_pass'  => wp_generate_password(),
                'user_email' => uniqid('watchhistory-test-', true) . '@example.invalid',
            ]
        );

        self::assertIsInt($user_id);
        $this->user_id = $user_id;

        wp_set_current_user($user_id);

        $first  = rest_do_request($this->watch_history_request(45, false));
        $second = rest_do_request($this->watch_history_request(80, true));

        self::assertSame(200, $first->get_status());
        self::assertSame(200, $second->get_status());
        self::assertSame(1, $this->count_rows_for_user($user_id));
    }

    /**
     * The REST endpoint records progress for a guest via their visitor
     * cookie, deduplicating repeated calls the same way the repository
     * does directly.
     */
    public function test_rest_endpoint_records_progress_for_guest(): void
    {
        wp_set_current_user(0);
        $visitor_token           = wp_generate_uuid4();
        $_COOKIE['tube_visitor'] = $visitor_token;

        $first  = rest_do_request($this->watch_history_request(10, false));
        $second = rest_do_request($this->watch_history_request(60, false));

        self::assertSame(200, $first->get_status());
        self::assertSame(200, $second->get_status());

        $row = $this->read_guest_row($visitor_token);
        self::assertSame(1, $row['row_count']);
        self::assertSame(60, $row['progress_seconds']);
    }

    /**
     * Out-of-range progress and an unknown video are both rejected — the
     * endpoint never trusts client input at face value.
     */
    public function test_rest_endpoint_rejects_invalid_input(): void
    {
        wp_set_current_user(0);
        $_COOKIE['tube_visitor'] = wp_generate_uuid4();

        $negative_progress = rest_do_request($this->watch_history_request(-5, false));
        self::assertSame(400, $negative_progress->get_status());

        $unknown_video = new WP_REST_Request('POST', '/tube/v1/videos/999999999/watch-history');
        $unknown_video->set_param('progress_seconds', 10);
        self::assertSame(404, rest_do_request($unknown_video)->get_status());
    }

    /**
     * Build a watch-history POST request for this test's video.
     *
     * @param int  $progress_seconds Progress to report.
     * @param bool $completed        Whether to report the video as completed.
     */
    private function watch_history_request(int $progress_seconds, bool $completed): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', "/tube/v1/videos/{$this->video_id}/watch-history");
        $request->set_param('progress_seconds', $progress_seconds);
        $request->set_param('completed', $completed);

        return $request;
    }
}
