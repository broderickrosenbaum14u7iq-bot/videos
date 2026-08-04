<?php
/**
 * The wp_tube_search_index JSON-array columns related-videos candidates are matched against.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Index;

/**
 * The `wp_tube_search_index` JSON-array columns
 * `DiscoveryRepositoryInterface::find_by_ids()` can match candidate
 * videos against, per `RelatedVideosFinder`'s priority cascade
 * (ARCHITECTURE.md §12 Phase 7: same categories, same actors, same
 * studio, similar tags).
 *
 * A closed enum rather than a raw column-name string — `find_by_ids()`
 * interpolates this value directly into a `JSON_CONTAINS(column, ...)`
 * clause (§2.6's `category_ids`/`tag_ids`/`actor_ids`/`studio_ids` are
 * plain `VARCHAR` columns storing JSON text, not a real MySQL `JSON`
 * column type, so they can't be parameterized as a value), and this
 * project's "prepared SQL everywhere" standard extends to identifiers
 * that reach SQL, not just values — a closed set of four known-safe
 * column names is exactly what makes that safe here, the same reasoning
 * `Tube_Core\Views\Repositories\VideoStatisticsRepository::top_by()`
 * already applies to a two-value column choice.
 */
enum CandidateColumn: string
{
    case CategoryIds = 'category_ids';
    case TagIds      = 'tag_ids';
    case ActorIds    = 'actor_ids';
    case StudioIds   = 'studio_ids';
}
