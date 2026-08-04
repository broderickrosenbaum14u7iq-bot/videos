<?php
/**
 * `wp tube-search index:rebuild`.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\CLI;

use Tube_Core\Content\VideoPostType;
use Tube_Search\Index\SearchIndexRepositoryInterface;
use Tube_Search\Index\VideoIndexer;
use WP_CLI;
use WP_Query;

/**
 * `wp tube-search index:rebuild` — full search-index consistency
 * rebuild, per ARCHITECTURE.md §7 (nightly) and §2.6 ("a full reindex
 * via Linux cron + WP-CLI that corrects any drift and is what actually
 * populates the index after a large import run completes").
 *
 * Walks every published video in batches (`fields => 'ids'`, meta/term
 * caching disabled — this command doesn't need WordPress's own
 * postmeta/term cache warmed, since `VideoIndexer` reads what it needs
 * through tube-core's own repository and `wp_get_post_terms()` directly;
 * this phase's "avoid loading unnecessary postmeta" instruction, applied
 * literally) via the same `VideoIndexer::index()` the incremental sync
 * subscriber uses, so bulk and incremental sync can never drift apart in
 * what they write. Finishes by removing any indexed row for a video that
 * is no longer in the published set — the removal half of "corrects any
 * drift," which the publish-status query alone can never catch.
 */
final class IndexCommand
{
    /**
     * How many videos to fetch per `WP_Query` page while rebuilding —
     * bounded so a 10,000-video rebuild never holds more than one
     * batch's worth of IDs in memory at once.
     */
    private const BATCH_SIZE = 200;

    /**
     * Construct the command around the indexer and repository it drives.
     *
     * @param VideoIndexer                   $indexer    Re-denormalizes each published video.
     * @param SearchIndexRepositoryInterface $repository Reports/removes stale rows.
     */
    public function __construct(
        private readonly VideoIndexer $indexer,
        private readonly SearchIndexRepositoryInterface $repository
    ) {
    }

    /**
     * Rebuild the entire search index from source data.
     *
     * ## EXAMPLES
     *
     *     wp tube-search index:rebuild
     *
     * @when after_wp_load
     */
    public function rebuild(): void
    {
        $published_ids = [];
        $paged         = 1;

        do {
            $query = new WP_Query(
                [
                    'post_type'              => VideoPostType::POST_TYPE,
                    'post_status'            => 'publish',
                    'fields'                 => 'ids',
                    'posts_per_page'         => self::BATCH_SIZE,
                    'paged'                  => $paged,
                    'orderby'                => 'ID',
                    'order'                  => 'ASC',
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                ]
            );

            $batch_ids  = array_map(static fn (mixed $id): int => is_scalar($id) ? (int) $id : 0, $query->posts);
            $batch_size = count($batch_ids);

            foreach ($batch_ids as $video_id) {
                $this->indexer->index($video_id);
                $published_ids[] = $video_id;
            }

            ++$paged;
        } while (self::BATCH_SIZE === $batch_size);

        $stale_ids = array_diff($this->repository->all_video_ids(), $published_ids);

        foreach ($stale_ids as $stale_id) {
            $this->repository->delete($stale_id);
        }

        WP_CLI::success(
            sprintf(
                'Indexed %d video(s), removed %d stale row(s).',
                count($published_ids),
                count($stale_ids)
            )
        );
    }
}
