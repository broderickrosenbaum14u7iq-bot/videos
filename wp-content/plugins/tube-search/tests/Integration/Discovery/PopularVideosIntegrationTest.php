<?php
/**
 * Integration tests for tube_search_trending()/tube_search_most_viewed() against real data.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Tests\Integration\Discovery;

use PHPUnit\Framework\TestCase;
use Tube_Cache\Plugin as Tube_Cache_Plugin;
use Tube_Core\Events\Dispatcher;
use Tube_Core\Events\EventCatalog;
use Tube_Core\Events\WordPressHookBus;
use Tube_Core\Plugin as Tube_Core_Plugin;
use Tube_Search\Index\SearchIndexRow;

/**
 * Exercises `tube_search_trending()`/`tube_search_most_viewed()` against
 * real `wp_tube_video_statistics` rows (via tube-core's own
 * `VideoStatisticsRepositoryInterface`, never a direct query against
 * tube-core's table — ARCHITECTURE.md §6.8), and proves the real
 * `tube_core.video.stats_rolled_up` event actually purges both listings
 * from the real Redis-backed cache
 * (`Tube_Cache\Events\CachePurgeSubscriber`'s real `add_action()` wiring,
 * not just its decision logic — already unit-tested against a fake in
 * tube-cache's own suite).
 */
final class PopularVideosIntegrationTest extends TestCase
{
    /**
     * Video posts created by a test, cleaned up in tearDown() regardless of outcome.
     *
     * @var int[]
     */
    private array $created_video_ids = [];

    /**
     * The real dispatcher, backed by WordPress's action hooks — the same
     * pathway `Tube_Core\Views\StatsRollup` uses in production.
     *
     * @var Dispatcher
     */
    private Dispatcher $dispatcher;

    /**
     * Build the real dispatcher for this test.
     */
    protected function setUp(): void
    {
        $this->dispatcher = new Dispatcher(new WordPressHookBus());
    }

    /**
     * Delete every video post and statistics row created by the test, and clear the caches touched.
     */
    protected function tearDown(): void
    {
        foreach ($this->created_video_ids as $video_id) {
            global $wpdb;
            /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup against a dedicated custom table owned by tube-core.
            $wpdb->delete($wpdb->prefix . 'tube_video_statistics', ['video_id' => $video_id], ['%d']);

            wp_delete_post($video_id, true);
        }

        Tube_Cache_Plugin::instance()->cache()->delete('trending');
        Tube_Cache_Plugin::instance()->cache()->delete('most_viewed');

        $this->created_video_ids = [];
    }

    /**
     * Trending() ranks by the real views_7d column, most_viewed() by the real views_total column.
     */
    public function test_trending_and_most_viewed_read_from_tube_core_statistics(): void
    {
        $low  = $this->create_published_video('Popularity Integration Low');
        $high = $this->create_published_video('Popularity Integration High');

        $statistics = Tube_Core_Plugin::instance()->video_statistics_repository();

        // views_total: $low ahead. views_7d: $high ahead -- proves the
        // two listings really read different columns, not the same one.
        $statistics->bump_totals(
            [
                $low  => 500,
                $high => 10,
            ]
        );
        $statistics->update_windows(
            [
                $low  => [
                    'today' => 1,
                    'd7'    => 5,
                    'd30'   => 5,
                ],
                $high => [
                    'today' => 90,
                    'd7'    => 90,
                    'd30'   => 90,
                ],
            ]
        );

        $trending    = tube_search_trending(5);
        $most_viewed = tube_search_most_viewed(5);

        self::assertSame(
            $high,
            $trending[0]->video_id ?? null,
            'trending() should rank by views_7d, with the high-7d-views video first.'
        );
        self::assertSame(
            $low,
            $most_viewed[0]->video_id ?? null,
            'most_viewed() should rank by views_total, with the high-views_total video first.'
        );
    }

    /**
     * A real tube_core.video.stats_rolled_up dispatch purges both cached listings.
     */
    public function test_stats_rolled_up_event_purges_the_cached_listings(): void
    {
        $video_id = $this->create_published_video('Popularity Cache Purge Video');

        Tube_Core_Plugin::instance()->video_statistics_repository()->bump_totals([$video_id => 1]);
        Tube_Core_Plugin::instance()->video_statistics_repository()->update_windows(
            [
                $video_id => [
                    'today' => 1,
                    'd7'    => 1,
                    'd30'   => 1,
                ],
            ]
        );

        // Warm both caches.
        tube_search_trending();
        tube_search_most_viewed();

        self::assertIsArray(Tube_Cache_Plugin::instance()->cache()->get('trending'));
        self::assertIsArray(Tube_Cache_Plugin::instance()->cache()->get('most_viewed'));

        $this->dispatcher->dispatch(
            EventCatalog::VIDEO_STATS_ROLLED_UP,
            [
                'video_id'    => $video_id,
                'views_total' => 1,
            ]
        );

        self::assertNull(Tube_Cache_Plugin::instance()->cache()->get('trending'));
        self::assertNull(Tube_Cache_Plugin::instance()->cache()->get('most_viewed'));
    }

    /**
     * A ranked video ID no longer present in the search index (e.g. never indexed) is silently skipped.
     */
    public function test_a_ranked_video_missing_from_the_index_is_skipped_not_an_error(): void
    {
        $video_id = $this->create_published_video('Popularity Skip Video');

        Tube_Core_Plugin::instance()->video_statistics_repository()->bump_totals([$video_id => 1]);
        Tube_Core_Plugin::instance()->video_statistics_repository()->update_windows(
            [
                $video_id => [
                    'today' => 1,
                    'd7'    => 1,
                    'd30'   => 1,
                ],
            ]
        );

        $trending = tube_search_trending(50);

        self::assertContainsOnlyInstancesOf(SearchIndexRow::class, $trending);
    }

    /**
     * Create a real published video post, tracked for teardown.
     *
     * @param string $title The post title.
     */
    private function create_published_video(string $title): int
    {
        $video_id = wp_insert_post(
            [
                'post_type'   => 'video',
                'post_title'  => $title,
                'post_status' => 'publish',
            ],
            true
        );

        self::assertIsInt($video_id);
        $this->created_video_ids[] = $video_id;

        return $video_id;
    }
}
