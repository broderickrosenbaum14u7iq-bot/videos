<?php
/**
 * Unit tests for StatsRollup.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Views;

use PHPUnit\Framework\TestCase;
use Tube_Core\Events\Dispatcher;
use Tube_Core\Events\EventCatalog;
use Tube_Core\Tests\Unit\Events\Fixtures\RecordingHookBus;
use Tube_Core\Tests\Unit\Views\Fixtures\InMemoryVideoStatisticsRepository;
use Tube_Core\Tests\Unit\Views\Fixtures\InMemoryVideoViewsRepository;
use Tube_Core\Views\StatsRollup;

/**
 * Exercises StatsRollup against fake repositories and a real Dispatcher
 * wired to a fake hook bus — no database, no WordPress.
 */
final class StatsRollupTest extends TestCase
{
    /**
     * The fake views repository the rollup under test reads from.
     *
     * @var InMemoryVideoViewsRepository
     */
    private InMemoryVideoViewsRepository $views_repository;

    /**
     * The fake statistics repository the rollup under test reads from/writes to.
     *
     * @var InMemoryVideoStatisticsRepository
     */
    private InMemoryVideoStatisticsRepository $statistics_repository;

    /**
     * The fake hook bus the dispatcher under test is wired to.
     *
     * @var RecordingHookBus
     */
    private RecordingHookBus $hook_bus;

    /**
     * The rollup under test.
     *
     * @var StatsRollup
     */
    private StatsRollup $rollup;

    /**
     * Build a fresh rollup and fakes for each test.
     */
    protected function setUp(): void
    {
        $this->views_repository      = new InMemoryVideoViewsRepository();
        $this->statistics_repository = new InMemoryVideoStatisticsRepository();
        $this->hook_bus              = new RecordingHookBus();
        $this->rollup                = new StatsRollup(
            $this->views_repository,
            $this->statistics_repository,
            new Dispatcher($this->hook_bus)
        );
    }

    /**
     * No videos with a statistics row means nothing to roll up.
     */
    public function test_no_videos_means_nothing_to_roll_up(): void
    {
        $updated = $this->rollup->rollup();

        self::assertSame(0, $updated);
        self::assertSame([], $this->statistics_repository->update_windows_calls);
        self::assertSame([], $this->hook_bus->dispatched);
    }

    /**
     * A video with recent views gets its real window sums written and announced.
     */
    public function test_a_video_with_recent_views_gets_its_windows_updated(): void
    {
        $this->statistics_repository->totals_to_return = [1 => 50];
        $this->views_repository->window_sums_to_return = [
            1 => [
                'today' => 3,
                'd7'    => 10,
                'd30'   => 40,
            ],
        ];

        $updated = $this->rollup->rollup();

        self::assertSame(1, $updated);
        self::assertSame(
            [
                [
                    1 => [
                        'today' => 3,
                        'd7'    => 10,
                        'd30'   => 40,
                    ],
                ],
            ],
            $this->statistics_repository->update_windows_calls
        );
        self::assertSame(
            [
                [
                    'hook'    => EventCatalog::VIDEO_STATS_ROLLED_UP,
                    'payload' => [
                        'video_id'    => 1,
                        'views_total' => 50,
                    ],
                ],
            ],
            $this->hook_bus->dispatched
        );
    }

    /**
     * A video with a statistics row but zero recent views gets its windows zeroed, not skipped.
     */
    public function test_a_video_with_no_recent_views_gets_zeroed_windows(): void
    {
        $this->statistics_repository->totals_to_return = [1 => 50];
        $this->views_repository->window_sums_to_return = [];

        $updated = $this->rollup->rollup();

        self::assertSame(1, $updated);
        self::assertSame(
            [
                [
                    1 => [
                        'today' => 0,
                        'd7'    => 0,
                        'd30'   => 0,
                    ],
                ],
            ],
            $this->statistics_repository->update_windows_calls
        );
    }

    /**
     * Every video with a statistics row is announced, even ones absent from window_sums().
     */
    public function test_every_video_is_announced_with_its_own_views_total(): void
    {
        $this->statistics_repository->totals_to_return = [
            1 => 50,
            2 => 5,
        ];
        $this->views_repository->window_sums_to_return = [
            1 => [
                'today' => 1,
                'd7'    => 2,
                'd30'   => 3,
            ],
        ];

        $this->rollup->rollup();

        $payloads = array_column($this->hook_bus->dispatched, 'payload');

        self::assertSame(
            [
                [
                    'video_id'    => 1,
                    'views_total' => 50,
                ],
                [
                    'video_id'    => 2,
                    'views_total' => 5,
                ],
            ],
            $payloads
        );
    }
}
