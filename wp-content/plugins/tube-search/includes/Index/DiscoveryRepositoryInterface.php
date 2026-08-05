<?php
/**
 * Contract for the read side of wp_tube_search_index data access.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Index;

/**
 * Contract for the read side of `wp_tube_search_index` data access —
 * every query tube-search's discovery/query classes run. See
 * `SearchIndexRepositoryInterface`'s docblock for why this is a
 * separate interface from the write side.
 *
 * Adopted per the interface-justification rule (§19.1): the real payoff
 * is a test fake `RelatedVideosFinder`, `PopularVideosQuery`,
 * `RecentlyAddedQuery`, and `SearchQuery` are each unit-tested against,
 * without a live database.
 */
interface DiscoveryRepositoryInterface
{
    /**
     * Fetch one video's index row.
     *
     * @param int $video_id The video post ID.
     *
     * @return SearchIndexRow|null The row, or null if this video isn't indexed.
     */
    public function find(int $video_id): ?SearchIndexRow;

    /**
     * Fetch several videos' index rows in one query — the batch
     * display-field lookup `PopularVideosQuery` uses after getting an
     * ordered ID list from tube-core's statistics repository, avoiding
     * one query per video (no N+1).
     *
     * @param int[] $video_ids The video IDs to fetch. Order of the result is not guaranteed to match.
     *
     * @return list<SearchIndexRow>
     */
    public function find_many(array $video_ids): array;

    /**
     * Find videos whose `$column` JSON array contains at least one of
     * `$ids` — the one query `RelatedVideosFinder` reuses for each of
     * its four candidate-source cascade steps (same categories, same
     * actors, same studio, similar tags).
     *
     * @param CandidateColumn $column            Which JSON-array column to match against.
     * @param int[]           $ids               Candidate IDs — a row matches if it shares any one of these.
     * @param int[]           $exclude_video_ids The source video, plus anything already found by an earlier step.
     * @param int             $limit             Maximum number of rows to return.
     *
     * @return list<SearchIndexRow> Highest `views_total` first, among matches.
     */
    public function find_by_ids(CandidateColumn $column, array $ids, array $exclude_video_ids, int $limit): array;

    /**
     * Find a random sample of videos — `RelatedVideosFinder`'s final
     * fallback step, once every real candidate source is exhausted.
     *
     * @param int[] $exclude_video_ids Video IDs to never return.
     * @param int   $limit             Maximum number of rows to return.
     *
     * @return list<SearchIndexRow>
     */
    public function find_random(array $exclude_video_ids, int $limit): array;

    /**
     * The most recently published videos, per ARCHITECTURE.md §12 Phase
     * 7's "Recently Added" — an indexed `ORDER BY published_at DESC LIMIT`.
     *
     * @param int $limit Maximum number of rows to return.
     *
     * @return list<SearchIndexRow> Most recently published first.
     */
    public function recently_added(int $limit): array;

    /**
     * Full-text search against `title`/`description`, per
     * ARCHITECTURE.md §5's `tube_search_query()` — a MySQL `FULLTEXT`
     * query against `search_text_idx`, ordered by relevance.
     *
     * @param string $query  The raw search query text.
     * @param int    $limit  Maximum number of rows to return.
     * @param int    $offset How many matching rows to skip (pagination).
     *
     * @return list<SearchIndexRow> Most relevant first.
     */
    public function search(string $query, int $limit, int $offset): array;

    /**
     * Find videos whose `$column` JSON array contains `$id`, paginated —
     * the category/tag/actor/studio archive listing query
     * `Tube_Search\Discovery\ArchiveVideosQuery` uses. Unlike
     * self::find_by_ids(), this takes a single ID (one archive page is
     * always for exactly one category/tag/actor/studio), supports a real
     * `$offset`, and orders by publish date rather than view count — an
     * archive page is a chronological listing, not a relevance-ranked one.
     *
     * @param CandidateColumn $column Which JSON-array column to match against.
     * @param int             $id     The category/tag/actor/studio ID a row must contain.
     * @param int             $limit  Maximum number of rows to return.
     * @param int             $offset How many matching rows to skip (pagination).
     *
     * @return list<SearchIndexRow> Most recently published first.
     */
    public function list_by_column(CandidateColumn $column, int $id, int $limit, int $offset): array;

    /**
     * The total number of videos whose `$column` JSON array contains
     * `$id` — the pagination-metadata companion to self::list_by_column().
     *
     * @param CandidateColumn $column Which JSON-array column to match against.
     * @param int             $id     The category/tag/actor/studio ID a row must contain.
     */
    public function count_by_column(CandidateColumn $column, int $id): int;
}
