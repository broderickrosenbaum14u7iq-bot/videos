<?php
/**
 * Unit tests for ViewsFlusher.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Views;

use PHPUnit\Framework\TestCase;
use Tube_Core\Tests\Unit\Views\Fixtures\InMemoryVideoStatisticsRepository;
use Tube_Core\Tests\Unit\Views\Fixtures\InMemoryVideoViewsRepository;
use Tube_Core\Tests\Unit\Views\Fixtures\InMemoryViewCounter;
use Tube_Core\Views\ViewsFlusher;

/**
 * Exercises ViewsFlusher against fake counter/repositories — no Redis, no database.
 */
final class ViewsFlusherTest extends TestCase
{
    /**
     * The fake counter the flusher under test reads from.
     *
     * @var InMemoryViewCounter
     */
    private InMemoryViewCounter $counter;

    /**
     * The fake views repository the flusher under test writes to.
     *
     * @var InMemoryVideoViewsRepository
     */
    private InMemoryVideoViewsRepository $views_repository;

    /**
     * The fake statistics repository the flusher under test writes to.
     *
     * @var InMemoryVideoStatisticsRepository
     */
    private InMemoryVideoStatisticsRepository $statistics_repository;

    /**
     * The flusher under test.
     *
     * @var ViewsFlusher
     */
    private ViewsFlusher $flusher;

    /**
     * Build a fresh flusher and fakes for each test.
     */
    protected function setUp(): void
    {
        $this->counter               = new InMemoryViewCounter();
        $this->views_repository      = new InMemoryVideoViewsRepository();
        $this->statistics_repository = new InMemoryVideoStatisticsRepository();
        $this->flusher               = new ViewsFlusher(
            $this->counter,
            $this->views_repository,
            $this->statistics_repository
        );
    }

    /**
     * An empty buffer flushes nothing and touches neither repository.
     */
    public function test_flushing_an_empty_buffer_touches_nothing(): void
    {
        $flushed = $this->flusher->flush();

        self::assertSame(0, $flushed);
        self::assertSame([], $this->views_repository->bulk_record_calls);
        self::assertSame([], $this->statistics_repository->bump_totals_calls);
    }

    /**
     * A non-empty buffer is bulk-recorded into both repositories and returns the video count.
     */
    public function test_flushing_a_buffer_writes_to_both_repositories(): void
    {
        $this->counter->record(1);
        $this->counter->record(1);
        $this->counter->record(2);

        $flushed = $this->flusher->flush();

        self::assertSame(2, $flushed);

        self::assertCount(1, $this->views_repository->bulk_record_calls);
        self::assertSame(
            [
                1 => 2,
                2 => 1,
            ],
            $this->views_repository->bulk_record_calls[0]['counts']
        );
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:00:00$/',
            $this->views_repository->bulk_record_calls[0]['view_hour']
        );

        self::assertSame(
            [
                [
                    1 => 2,
                    2 => 1,
                ],
            ],
            $this->statistics_repository->bump_totals_calls
        );
    }

    /**
     * Flushing genuinely empties the counter — a second flush in a row finds nothing.
     */
    public function test_flushing_empties_the_counter(): void
    {
        $this->counter->record(1);

        $this->flusher->flush();
        $second_flush = $this->flusher->flush();

        self::assertSame(0, $second_flush);
    }
}
