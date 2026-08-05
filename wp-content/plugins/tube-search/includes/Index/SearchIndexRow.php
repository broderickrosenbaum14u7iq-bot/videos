<?php
/**
 * A read-only snapshot of one wp_tube_search_index row.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Index;

/**
 * A read-only snapshot of one wp_tube_search_index row, per
 * ARCHITECTURE.md §2.6 — the shape every discovery query
 * (`Tube_Search\Discovery\*`, `Tube_Search\Search\SearchQuery`) returns
 * to its caller. Never carries a permalink — §2.6's schema has no such
 * column; a theme calls `get_permalink($row->video_id)` itself (a plain
 * WordPress core call, not a second query against anything tube-search owns).
 */
final class SearchIndexRow
{
    /**
     * Construct an immutable snapshot of one search-index row.
     *
     * @param int         $video_id         The video post ID.
     * @param string      $title            The video's title.
     * @param string|null $description      The video's description/excerpt, if any.
     * @param int[]       $category_ids     `video_category` term IDs.
     * @param int[]       $tag_ids          `video_tag` term IDs.
     * @param int[]       $actor_ids        Actor IDs (§14) — empty until tube-admin (Phase 10) assigns any.
     * @param int[]       $studio_ids       Studio IDs (§14) — empty until tube-admin (Phase 10) assigns any.
     * @param int|null    $duration_seconds The video's duration, if known.
     * @param int         $views_total      Denormalized all-time view count.
     * @param string|null $published_at     MySQL `DATETIME` string, or null if never published.
     */
    public function __construct(
        public readonly int $video_id,
        public readonly string $title,
        public readonly ?string $description,
        public readonly array $category_ids,
        public readonly array $tag_ids,
        public readonly array $actor_ids,
        public readonly array $studio_ids,
        public readonly ?int $duration_seconds,
        public readonly int $views_total,
        public readonly ?string $published_at
    ) {
    }

    /**
     * Convert to a plain array — the shape cached through
     * `SearchCacheInterface`. `Tube_Cache\Cache\RedisCache` unserializes
     * with `allowed_classes: false` (an object-injection guard), which
     * would otherwise turn a cached `SearchIndexRow` back into a broken
     * `__PHP_Incomplete_Class` instead of a usable object — every
     * discovery query class caches the array form via this method and
     * `self::from_array()` instead.
     *
     * @return array<string, mixed>
     */
    public function to_array(): array
    {
        return [
            'video_id'         => $this->video_id,
            'title'            => $this->title,
            'description'      => $this->description,
            'category_ids'     => $this->category_ids,
            'tag_ids'          => $this->tag_ids,
            'actor_ids'        => $this->actor_ids,
            'studio_ids'       => $this->studio_ids,
            'duration_seconds' => $this->duration_seconds,
            'views_total'      => $this->views_total,
            'published_at'     => $this->published_at,
        ];
    }

    /**
     * Reconstruct from self::to_array()'s shape.
     *
     * @param array<string, mixed> $data As returned by self::to_array().
     */
    public static function from_array(array $data): self
    {
        /** @var array{video_id: int, title: string, description: string|null, category_ids: int[], tag_ids: int[], actor_ids: int[], studio_ids: int[], duration_seconds: int|null, views_total: int, published_at: string|null} $data */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        return new self(
            $data['video_id'],
            $data['title'],
            $data['description'],
            $data['category_ids'],
            $data['tag_ids'],
            $data['actor_ids'],
            $data['studio_ids'],
            $data['duration_seconds'],
            $data['views_total'],
            $data['published_at']
        );
    }
}
