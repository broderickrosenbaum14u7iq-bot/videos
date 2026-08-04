<?php
/**
 * Test fixture: an in-memory PopularityRepositoryInterface.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Tests\Unit\Discovery\Fixtures;

use Tube_Search\Discovery\PopularityRepositoryInterface;

/**
 * An in-memory PopularityRepositoryInterface — pre-programmed ranked
 * lists, no database.
 */
final class InMemoryPopularityRepository implements PopularityRepositoryInterface
{
    /**
     * What top_by_total() should return.
     *
     * @var list<array{video_id: int, count: int}>
     */
    public array $top_by_total_return = [];

    /**
     * What top_by_recent() should return.
     *
     * @var list<array{video_id: int, count: int}>
     */
    public array $top_by_recent_return = [];

    /**
     * {@inheritDoc}
     *
     * @param int $limit Maximum number of entries to return.
     *
     * @return list<array{video_id: int, count: int}>
     */
    public function top_by_total(int $limit): array
    {
        return array_slice($this->top_by_total_return, 0, $limit);
    }

    /**
     * {@inheritDoc}
     *
     * @param int $limit Maximum number of entries to return.
     *
     * @return list<array{video_id: int, count: int}>
     */
    public function top_by_recent(int $limit): array
    {
        return array_slice($this->top_by_recent_return, 0, $limit);
    }
}
