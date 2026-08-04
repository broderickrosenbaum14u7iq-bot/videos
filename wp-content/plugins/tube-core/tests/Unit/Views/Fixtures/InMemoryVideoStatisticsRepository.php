<?php
/**
 * Test fixture: an in-memory VideoStatisticsRepositoryInterface.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Views\Fixtures;

use Tube_Core\Views\Repositories\VideoStatisticsRepositoryInterface;

/**
 * An in-memory VideoStatisticsRepositoryInterface that records what it
 * was asked to do and returns pre-programmed results — no database.
 */
final class InMemoryVideoStatisticsRepository implements VideoStatisticsRepositoryInterface
{
    /**
     * Every bump_totals() call this fake received, in order.
     *
     * @var list<array<int, int>>
     */
    public array $bump_totals_calls = [];

    /**
     * What all_totals() should return.
     *
     * @var array<int, int>
     */
    public array $totals_to_return = [];

    /**
     * Every update_windows() call this fake received, in order.
     *
     * @var list<array<int, array{today: int, d7: int, d30: int}>>
     */
    public array $update_windows_calls = [];

    /**
     * {@inheritDoc}
     *
     * @param array<int, int> $counts Video ID => view count to add to views_total.
     */
    public function bump_totals(array $counts): void
    {
        $this->bump_totals_calls[] = $counts;
    }

    /**
     * {@inheritDoc}
     *
     * @return array<int, int> Video ID => current views_total.
     */
    public function all_totals(): array
    {
        return $this->totals_to_return;
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int, array{today: int, d7: int, d30: int}> $windows Video ID => window values.
     */
    public function update_windows(array $windows): void
    {
        $this->update_windows_calls[] = $windows;
    }

    /**
     * What top_by_views_total() should return.
     *
     * @var list<array{video_id: int, count: int}>
     */
    public array $top_by_views_total_to_return = [];

    /**
     * What top_by_views_7d() should return.
     *
     * @var list<array{video_id: int, count: int}>
     */
    public array $top_by_views_7d_to_return = [];

    /**
     * {@inheritDoc}
     *
     * @param int $limit Maximum number of videos to return.
     *
     * @return list<array{video_id: int, count: int}> Highest `views_total` first.
     */
    public function top_by_views_total(int $limit): array
    {
        return array_slice($this->top_by_views_total_to_return, 0, $limit);
    }

    /**
     * {@inheritDoc}
     *
     * @param int $limit Maximum number of videos to return.
     *
     * @return list<array{video_id: int, count: int}> Highest `views_7d` first.
     */
    public function top_by_views_7d(int $limit): array
    {
        return array_slice($this->top_by_views_7d_to_return, 0, $limit);
    }
}
