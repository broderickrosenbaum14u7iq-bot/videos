<?php
/**
 * Integration tests for the real view-recording pipeline: record -> flush -> canonical DB.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Integration\Views;

use Predis\Client;
use PHPUnit\Framework\TestCase;
use Tube_Core\Plugin as Tube_Core_Plugin;
use Tube_Core\Views\RedisViewCounter;
use Tube_Core\Views\Repositories\VideoStatisticsRepository;
use Tube_Core\Views\Repositories\VideoViewsRepository;
use Tube_Core\Views\ViewBaselineSubscriber;
use Tube_Core\Views\ViewController;
use WP_REST_Request;

/**
 * Regression coverage for the real bug found in manual testing: real
 * videos were watched, but `wp_tube_video_statistics.views_total` never
 * moved — `Tube_Core\Plugin::view_recorder()->record()` had no caller
 * anywhere (see `Tube_Core\Views\ViewController`'s own docblock for the
 * full root-cause account). This exercises the exact same public
 * entry point (`Plugin::instance()->view_recorder()`) a real request
 * now uses (`ViewController::handle()`), against real Redis and a real
 * database — proving the fix end to end, not just that each class's own
 * unit tests pass against fakes.
 *
 * Also covers the 2026-08-25 policy reversal: every intentional play
 * action counts, with no per-viewer/IP/cookie deduplication window
 * anymore (`ViewController::handle()`'s own docblock has the full
 * before/after account of what was removed and why) — the repeated-play
 * tests below exercise `ViewController::handle()` itself directly,
 * calling it multiple times for the same video, proving the real
 * controller has no dedup left, not just that `ViewRecorder::record()`
 * (a lower layer that never had any) doesn't.
 *
 * The flush step is replicated here (not delegated to
 * `Tube_Core\Views\ViewsFlusher`, whose own `flush()` doesn't expose a
 * per-video breakdown) via a fresh `RedisViewCounter` against the same
 * real Redis connection `Plugin::instance()->view_recorder()` itself
 * used — Redis state lives on the Redis server, not in either PHP
 * object, so this reads/drains the exact same buffer, the same way the
 * real `wp tube-core views:flush` cron job would.
 */
final class ViewRecordingIntegrationTest extends TestCase
{
    /**
     * A real video post created for the test.
     *
     * @var int
     */
    private int $video_id;

    /**
     * Create a real published video (ViewBaselineSubscriber seeds its statistics row on publish).
     */
    protected function setUp(): void
    {
        $video_id = wp_insert_post(
            [
                'post_type'   => 'video',
                'post_title'  => 'View Recording Integration Test Video',
                'post_status' => 'publish',
            ],
            true
        );

        self::assertIsInt($video_id);
        $this->video_id = $video_id;
    }

    /**
     * Delete the video and its statistics/views rows.
     */
    protected function tearDown(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup against dedicated custom tables.
        $wpdb->delete($wpdb->prefix . 'tube_video_statistics', ['video_id' => $this->video_id], ['%d']);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup against dedicated custom tables.
        $wpdb->delete($wpdb->prefix . 'tube_video_views', ['video_id' => $this->video_id], ['%d']);

        wp_delete_post($this->video_id, true);
    }

    /**
     * Publishing a video seeds its statistics row at the baseline immediately — not left at 0/absent.
     */
    public function test_publishing_seeds_the_baseline_immediately(): void
    {
        self::assertSame(ViewBaselineSubscriber::BASELINE_VIEWS, $this->current_views_total());
    }

    /**
     * Recording one real view and flushing it increments the real canonical
     * counter by exactly 1 — the exact "0 -> 1" (here, baseline -> baseline + 1)
     * behavior required. Uses `Plugin::instance()->view_recorder()`, the
     * exact same public entry point the real REST endpoint
     * (`ViewController::handle()`) calls.
     */
    public function test_recording_and_flushing_one_view_increments_the_real_counter_by_exactly_one(): void
    {
        $before = $this->current_views_total();

        Tube_Core_Plugin::instance()->view_recorder()->record($this->video_id);

        $flushed = $this->flush_real_buffer();

        self::assertSame($before + 1, $this->current_views_total());
        self::assertSame(1, $flushed[ $this->video_id ] ?? null);
    }

    /**
     * Recording three real views and flushing once increments the real
     * counter by exactly 3, in one atomic write — not three separate
     * read-then-write round trips.
     */
    public function test_recording_three_views_and_flushing_increments_by_exactly_three(): void
    {
        $before = $this->current_views_total();

        Tube_Core_Plugin::instance()->view_recorder()->record($this->video_id);
        Tube_Core_Plugin::instance()->view_recorder()->record($this->video_id);
        Tube_Core_Plugin::instance()->view_recorder()->record($this->video_id);

        $this->flush_real_buffer();

        self::assertSame($before + 3, $this->current_views_total());
    }

    /**
     * The real controller, called five separate times for the same
     * video — simulating the same visitor genuinely clicking play five
     * separate times in one sitting — records all five, with no
     * suppression. Calls `ViewController::handle()` directly (a real
     * `WP_REST_Request`), not `ViewRecorder::record()` — that lower
     * layer never had any dedup to remove, so exercising it alone
     * wouldn't prove the controller itself doesn't reintroduce any.
     */
    public function test_controller_records_every_separate_call_with_no_deduplication(): void
    {
        $before     = $this->current_views_total();
        $controller = new ViewController(Tube_Core_Plugin::instance()->view_recorder());

        for ($i = 0; $i < 5; $i++) {
            $request = new WP_REST_Request('POST', '/tube/v1/videos/(?P<id>\d+)/view');
            $request->set_param('id', (string) $this->video_id);

            $response = $controller->handle($request);

            self::assertSame(200, $response->get_status());
        }

        $this->flush_real_buffer();

        self::assertSame(
            $before + 5,
            $this->current_views_total(),
            'Five separate calls must record five separate views — no per-request/per-viewer dedup.'
        );
    }

    /**
     * The real video's current `views_total`.
     */
    private function current_views_total(): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent.
        $value = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT views_total FROM %i WHERE video_id = %d',
                $wpdb->prefix . 'tube_video_statistics',
                $this->video_id
            )
        );

        return null === $value ? 0 : (int) $value;
    }

    /**
     * Flush the real Redis-buffered view counts into the real database —
     * the exact same three steps `Tube_Core\Views\ViewsFlusher::flush()`
     * (backing `wp tube-core views:flush`) performs, replicated here
     * rather than delegated to it only so this method can also return
     * the per-video breakdown for a precise assertion (`flush()` itself
     * only returns a distinct-video count).
     *
     * @return array<int, int> Video ID => flushed count.
     */
    private function flush_real_buffer(): array
    {
        $counter = new RedisViewCounter($this->redis_client());
        $counts  = $counter->flush();

        if ([] !== $counts) {
            (new VideoViewsRepository())->bulk_record($counts, gmdate('Y-m-d H:00:00'));
            (new VideoStatisticsRepository())->bump_totals($counts);
        }

        return $counts;
    }

    /**
     * A real Predis client against this environment's configured Redis — the same connection
     * `Tube_Core\Plugin`'s own (private) `redis_client()` would build.
     */
    private function redis_client(): Client
    {
        $host = defined('TUBE_CORE_REDIS_HOST') ? TUBE_CORE_REDIS_HOST : '127.0.0.1';
        $port = defined('TUBE_CORE_REDIS_PORT') ? TUBE_CORE_REDIS_PORT : 6379;

        return new Client(
            [
                'host' => $host,
                'port' => $port,
            ]
        );
    }
}
