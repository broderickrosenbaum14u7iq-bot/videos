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

    /**
     * What list_all() should return.
     *
     * @var list<array{video_id: int, views_total: int, views_today: int, views_7d: int, views_30d: int}>
     */
    public array $list_all_to_return = [];

    /**
     * What count_all() should return.
     *
     * @var int
     */
    public int $count_all_to_return = 0;

    /**
     * {@inheritDoc}
     *
     * @param 'views_total'|'views_today'|'views_7d'|'views_30d' $order_by Column to sort by, highest first.
     * @param int                                                $limit    Maximum number of videos to return.
     * @param int                                                $offset   Number of videos to skip, for pagination.
     *
     * @return list<array{video_id: int, views_total: int, views_today: int, views_7d: int, views_30d: int}>
     */
    public function list_all(string $order_by, int $limit, int $offset): array
    {
        return array_slice($this->list_all_to_return, $offset, $limit);
    }

    /**
     * {@inheritDoc}
     */
    public function count_all(): int
    {
        return $this->count_all_to_return;
    }

    /**
     * Each video's current likes_total, keyed by video ID.
     *
     * @var array<int, int>
     */
    public array $likes_totals = [];

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video post ID.
     */
    public function likes_total(int $video_id): int
    {
        return $this->likes_totals[ $video_id ] ?? 0;
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video post ID.
     */
    public function increment_likes(int $video_id): void
    {
        $this->likes_totals[ $video_id ] = ($this->likes_totals[ $video_id ] ?? 0) + 1;
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video post ID.
     */
    public function decrement_likes(int $video_id): void
    {
        $this->likes_totals[ $video_id ] = max(0, ($this->likes_totals[ $video_id ] ?? 0) - 1);
    }

    /**
     * Every ensure_baseline() call this fake received, in order.
     *
     * @var list<array{video_id: int, baseline: int}>
     */
    public array $ensure_baseline_calls = [];

    /**
     * Video IDs this fake should report as already having a row (so a
     * test can distinguish "seeded" from "already existed, untouched").
     *
     * @var int[]
     */
    public array $existing_video_ids = [];

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video post ID.
     * @param int $baseline The `views_total` to seed a brand-new row with.
     */
    public function ensure_baseline(int $video_id, int $baseline): void
    {
        $this->ensure_baseline_calls[] = [
            'video_id' => $video_id,
            'baseline' => $baseline,
        ];

        if (! in_array($video_id, $this->existing_video_ids, true)) {
            $this->existing_video_ids[]          = $video_id;
            $this->totals_to_return[ $video_id ] = $baseline;
        }
    }
}
