<?php
/**
 * Test fixture: an in-memory VideoViewsRepositoryInterface.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Views\Fixtures;

use Tube_Core\Views\Repositories\VideoViewsRepositoryInterface;

/**
 * An in-memory VideoViewsRepositoryInterface that records what it was
 * asked to do and returns pre-programmed results — no database.
 */
final class InMemoryVideoViewsRepository implements VideoViewsRepositoryInterface
{
    /**
     * Every bulk_record() call this fake received, in order.
     *
     * @var list<array{counts: array<int, int>, view_hour: string}>
     */
    public array $bulk_record_calls = [];

    /**
     * What window_sums() should return.
     *
     * @var array<int, array{today: int, d7: int, d30: int}>
     */
    public array $window_sums_to_return = [];

    /**
     * Every purge_before() cutoff this fake received, in order.
     *
     * @var list<string>
     */
    public array $purge_before_calls = [];

    /**
     * What purge_before() should return.
     *
     * @var int
     */
    public int $purge_before_return = 0;

    /**
     * {@inheritDoc}
     *
     * @param array<int, int> $counts    Video ID => view count to add, for this one hour bucket.
     * @param string          $view_hour MySQL `DATETIME` string truncated to the hour.
     */
    public function bulk_record(array $counts, string $view_hour): void
    {
        $this->bulk_record_calls[] = [
            'counts'    => $counts,
            'view_hour' => $view_hour,
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @param string $today_start     MySQL `DATETIME` string: start of "today" (inclusive).
     * @param string $seven_days_ago  MySQL `DATETIME` string: seven-day window start (inclusive).
     * @param string $thirty_days_ago MySQL `DATETIME` string: thirty-day window start (inclusive).
     *
     * @return array<int, array{today: int, d7: int, d30: int}> Video ID => window sums.
     */
    public function window_sums(string $today_start, string $seven_days_ago, string $thirty_days_ago): array
    {
        return $this->window_sums_to_return;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $cutoff MySQL `DATETIME` string.
     */
    public function purge_before(string $cutoff): int
    {
        $this->purge_before_calls[] = $cutoff;

        return $this->purge_before_return;
    }
}
