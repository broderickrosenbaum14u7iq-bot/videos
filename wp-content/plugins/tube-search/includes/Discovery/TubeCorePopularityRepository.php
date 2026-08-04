<?php
/**
 * Real PopularityRepositoryInterface implementation, backed by tube-core's statistics table.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Discovery;

use Tube_Core\Plugin as Tube_Core_Plugin;

/**
 * Real PopularityRepositoryInterface implementation — a thin pass-
 * through to `Tube_Core\Plugin::instance()->video_statistics_repository()`,
 * tube-core's own precomputed-statistics read API (Phase 4/7). Never
 * queries `wp_tube_video_statistics` directly (that would violate the
 * "no plugin queries another plugin's tables" rule,
 * `DEVELOPMENT_RULES.md` §6.8) and never aggregates anything itself —
 * both `top_by_total()`/`top_by_recent()` are already-indexed
 * `ORDER BY ... LIMIT` reads on tube-core's side.
 *
 * Safe to reference `Tube_Core\Plugin` directly despite tube-search's
 * own composer.json having no dependency on tube-core's package (see
 * `PopularityRepositoryInterface`'s docblock) — this class is never
 * loaded by tube-search's own standalone PHPUnit suite, only inside real
 * WordPress, where `Requires Plugins: tube-core` guarantees tube-core is
 * active and loaded first — verified live, not unit-tested.
 */
final class TubeCorePopularityRepository implements PopularityRepositoryInterface
{
    /**
     * {@inheritDoc}
     *
     * @param int $limit Maximum number of videos to return.
     *
     * @return list<array{video_id: int, count: int}>
     */
    public function top_by_total(int $limit): array
    {
        return Tube_Core_Plugin::instance()->video_statistics_repository()->top_by_views_total($limit);
    }

    /**
     * {@inheritDoc}
     *
     * @param int $limit Maximum number of videos to return.
     *
     * @return list<array{video_id: int, count: int}>
     */
    public function top_by_recent(int $limit): array
    {
        return Tube_Core_Plugin::instance()->video_statistics_repository()->top_by_views_7d($limit);
    }
}
