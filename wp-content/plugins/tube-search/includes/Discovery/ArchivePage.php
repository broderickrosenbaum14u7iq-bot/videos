<?php
/**
 * One page of an archive listing (category/tag/actor/studio), plus its pagination metadata.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Discovery;

use Tube_Search\Index\SearchIndexRow;

/**
 * One page of an archive listing (category/tag/actor/studio), plus the
 * pagination metadata (`$total`, `$page`, `$per_page`) a theme template
 * and `tube-seo`'s pagination meta tags (rel prev/next) both need — the
 * shape `Tube_Search\Discovery\ArchiveVideosQuery` returns.
 */
final class ArchivePage
{
    /**
     * Construct an immutable snapshot of one archive listing page.
     *
     * @param SearchIndexRow[] $items    The videos on this page.
     * @param int              $total    The total number of videos in this archive, across all pages.
     * @param int              $page     The current page number (1-indexed).
     * @param int              $per_page Results per page.
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $per_page
    ) {
    }

    /**
     * Convert to a plain array — the shape `ArchiveVideosQuery` caches
     * through `SearchCacheInterface`. See `SearchIndexRow::to_array()`'s
     * docblock for why: `Tube_Cache\Cache\RedisCache` unserializes with
     * `allowed_classes: false`, which would otherwise turn a cached
     * `ArchivePage` (and its nested `SearchIndexRow`s) back into broken
     * `__PHP_Incomplete_Class` objects instead of usable ones.
     *
     * @return array<string, mixed>
     */
    public function to_array(): array
    {
        return [
            'items'    => array_map(static fn (SearchIndexRow $row): array => $row->to_array(), $this->items),
            'total'    => $this->total,
            'page'     => $this->page,
            'per_page' => $this->per_page,
        ];
    }

    /**
     * Reconstruct from self::to_array()'s shape.
     *
     * @param array<string, mixed> $data As returned by self::to_array().
     */
    public static function from_array(array $data): self
    {
        /** @var array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int} $data */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        return new self(
            array_map(SearchIndexRow::from_array(...), $data['items']),
            $data['total'],
            $data['page'],
            $data['per_page']
        );
    }
}
