<?php
/**
 * Test fixture: an in-memory, genuinely-functional DiscoveryRepositoryInterface.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Tests\Unit\Discovery\Fixtures;

use Tube_Search\Index\CandidateColumn;
use Tube_Search\Index\DiscoveryRepositoryInterface;
use Tube_Search\Index\SearchIndexRow;

/**
 * An in-memory DiscoveryRepositoryInterface that genuinely filters/
 * matches seeded rows (not just a call recorder returning pre-programmed
 * results) — this is what lets `RelatedVideosFinderTest` exercise the
 * real priority-cascade decision logic against realistic candidate data.
 */
final class InMemoryDiscoveryRepository implements DiscoveryRepositoryInterface
{
    /**
     * Seeded rows, keyed by video_id.
     *
     * @var array<int, SearchIndexRow>
     */
    private array $rows = [];

    /**
     * How many times find_by_ids() has been called, per column — lets
     * tests assert a cascade step was skipped entirely (e.g. because an
     * earlier step already filled the limit) without needing to inspect
     * SQL that was never generated.
     *
     * @var array<string, int>
     */
    public array $find_by_ids_calls = [];

    /**
     * How many times find() has been called — used to prove a cached
     * result was reused instead of recomputed.
     *
     * @var int
     */
    public int $find_calls = 0;

    /**
     * Seed one row into the fake table.
     *
     * @param SearchIndexRow $row The row to seed.
     */
    public function seed(SearchIndexRow $row): void
    {
        $this->rows[ $row->video_id ] = $row;
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video post ID.
     */
    public function find(int $video_id): ?SearchIndexRow
    {
        ++$this->find_calls;

        return $this->rows[ $video_id ] ?? null;
    }

    /**
     * {@inheritDoc}
     *
     * @param int[] $video_ids The video IDs to fetch.
     *
     * @return list<SearchIndexRow>
     */
    public function find_many(array $video_ids): array
    {
        $result = [];

        foreach ($video_ids as $video_id) {
            if (isset($this->rows[ $video_id ])) {
                $result[] = $this->rows[ $video_id ];
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param CandidateColumn $column            Which JSON-array column to match against.
     * @param int[]           $ids               Candidate IDs.
     * @param int[]           $exclude_video_ids Video IDs to never return.
     * @param int             $limit             Maximum number of rows to return.
     *
     * @return list<SearchIndexRow>
     */
    public function find_by_ids(CandidateColumn $column, array $ids, array $exclude_video_ids, int $limit): array
    {
        $this->find_by_ids_calls[ $column->value ] = ($this->find_by_ids_calls[ $column->value ] ?? 0) + 1;

        $result = [];

        foreach ($this->rows as $row) {
            if (in_array($row->video_id, $exclude_video_ids, true)) {
                continue;
            }

            if ([] !== array_intersect($this->column_values($row, $column), $ids)) {
                $result[] = $row;
            }

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param int[] $exclude_video_ids Video IDs to never return.
     * @param int   $limit             Maximum number of rows to return.
     *
     * @return list<SearchIndexRow>
     */
    public function find_random(array $exclude_video_ids, int $limit): array
    {
        $result = [];

        foreach ($this->rows as $row) {
            if (in_array($row->video_id, $exclude_video_ids, true)) {
                continue;
            }

            $result[] = $row;

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @param int $limit Maximum number of rows to return.
     *
     * @return list<SearchIndexRow>
     */
    public function recently_added(int $limit): array
    {
        $rows = array_values($this->rows);

        usort(
            $rows,
            static function (SearchIndexRow $a, SearchIndexRow $b): int {
                $a_published_at = (string) $a->published_at;
                $b_published_at = (string) $b->published_at;

                return strcmp($b_published_at, $a_published_at);
            }
        );

        return array_slice($rows, 0, $limit);
    }

    /**
     * {@inheritDoc} A simple substring match against title/description —
     * good enough for unit-testing `SearchQuery`'s own logic (paging,
     * caching), which doesn't depend on real FULLTEXT ranking.
     *
     * @param string $query  The raw search query text.
     * @param int    $limit  Maximum number of rows to return.
     * @param int    $offset How many matching rows to skip.
     *
     * @return list<SearchIndexRow>
     */
    public function search(string $query, int $limit, int $offset): array
    {
        $result = [];

        foreach ($this->rows as $row) {
            $matches = false !== stripos($row->title, $query)
                || (null !== $row->description && false !== stripos($row->description, $query));

            if ($matches) {
                $result[] = $row;
            }
        }

        return array_slice($result, $offset, $limit);
    }

    /**
     * {@inheritDoc}
     *
     * @param CandidateColumn $column Which JSON-array column to match against.
     * @param int             $id     The ID a row must contain.
     * @param int             $limit  Maximum number of rows to return.
     * @param int             $offset How many matching rows to skip.
     *
     * @return list<SearchIndexRow>
     */
    public function list_by_column(CandidateColumn $column, int $id, int $limit, int $offset): array
    {
        $matches = [];

        foreach ($this->rows as $row) {
            if (in_array($id, $this->column_values($row, $column), true)) {
                $matches[] = $row;
            }
        }

        usort(
            $matches,
            static function (SearchIndexRow $a, SearchIndexRow $b): int {
                $a_published_at = (string) $a->published_at;
                $b_published_at = (string) $b->published_at;

                return strcmp($b_published_at, $a_published_at);
            }
        );

        return array_slice($matches, $offset, $limit);
    }

    /**
     * {@inheritDoc}
     *
     * @param CandidateColumn $column Which JSON-array column to match against.
     * @param int             $id     The ID a row must contain.
     */
    public function count_by_column(CandidateColumn $column, int $id): int
    {
        $count = 0;

        foreach ($this->rows as $row) {
            if (in_array($id, $this->column_values($row, $column), true)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Read the value of one candidate column from a row.
     *
     * @param SearchIndexRow  $row    The row to read from.
     * @param CandidateColumn $column Which column to read.
     *
     * @return int[]
     */
    private function column_values(SearchIndexRow $row, CandidateColumn $column): array
    {
        return match ($column) {
            CandidateColumn::CategoryIds => $row->category_ids,
            CandidateColumn::TagIds => $row->tag_ids,
            CandidateColumn::ActorIds => $row->actor_ids,
            CandidateColumn::StudioIds => $row->studio_ids,
        };
    }
}
