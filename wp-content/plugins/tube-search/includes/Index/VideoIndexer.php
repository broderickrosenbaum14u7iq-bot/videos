<?php
/**
 * Denormalizes one video's current data into wp_tube_search_index.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Index;

use Tube_Core\Content\CategoryTaxonomy;
use Tube_Core\Content\TagTaxonomy;
use Tube_Core\Content\VideoPostType;
use Tube_Core\Plugin as Tube_Core_Plugin;
use WP_Post;

/**
 * Denormalizes one video's current data (post, taxonomies, tube-core's
 * metadata) into `wp_tube_search_index`, per ARCHITECTURE.md §2.6. The
 * one piece of logic `Tube_Search\Events\SearchIndexSyncSubscriber`
 * (incremental, event-driven) and `Tube_Search\CLI\IndexCommand` (bulk
 * `index:rebuild`) both call, so the two sync paths can never drift
 * apart in what they actually write.
 *
 * `wp_tube_search_index` holds **published videos only** — a video that
 * isn't `publish` status (draft, trashed, or simply gone) is actively
 * removed by self::index(), not left with a stale row. This matters
 * because `tube_core.video.updated` fires on *every* save, not just
 * published ones (a draft edit fires it too), and because a previously-
 * published video can be unpublished later.
 * `actor_ids`/`studio_ids` are read fresh from tube-core's
 * `ActorRepositoryInterface::actor_ids_for_video()`/
 * `StudioRepositoryInterface::studio_ids_for_video()` on every resync,
 * the same "always read the current real relationship, never trust what
 * was last written" treatment `category_ids`/`tag_ids` already get from
 * `wp_get_post_terms()`, per ARCHITECTURE.md §14's dedicated-table
 * design for actor/studio.
 *
 * WordPress/tube-core-coupled throughout (`get_post()`,
 * `wp_get_post_terms()`, `Tube_Core\Plugin::instance()`) — verified via
 * integration tests and live checks, not unit-tested, the same split
 * this project applies to every thin real-data adapter. Safe to
 * reference `Tube_Core\*` types directly despite tube-search's own
 * composer.json having no dependency on tube-core's package
 * (DEVELOPMENT_RULES.md §2): this class is never loaded by tube-search's
 * own standalone PHPUnit suite, only inside real WordPress, where
 * `Requires Plugins: tube-core` in `tube-search.php`'s header already
 * guarantees tube-core is active and loaded first.
 */
final class VideoIndexer
{
    /**
     * Must match `Tube_Core\Content\CategoryTaxonomy::TAXONOMY` exactly
     * — referenced as a real import here (not a literal string) since
     * this class is already tube-core-coupled by design; see this
     * class's own docblock for why that's safe.
     */
    private const CATEGORY_TAXONOMY = CategoryTaxonomy::TAXONOMY;

    /**
     * Must match `Tube_Core\Content\TagTaxonomy::TAXONOMY` exactly.
     */
    private const TAG_TAXONOMY = TagTaxonomy::TAXONOMY;

    /**
     * The default excerpt length, in words, when a video has no explicit
     * excerpt — matches WordPress core's own default
     * (`wp_trim_excerpt()`), so indexed descriptions read the same way
     * an auto-generated core excerpt would.
     */
    private const AUTO_EXCERPT_WORDS = 55;

    /**
     * Construct around the repository this indexer writes to and reads
     * from (an intersection type, not a new interface — see
     * `SearchIndexRepository`'s own docblock for why one concrete class
     * implements both halves).
     *
     * @param SearchIndexRepositoryInterface&DiscoveryRepositoryInterface $repository Written to and read from.
     */
    public function __construct(
        private readonly SearchIndexRepositoryInterface&DiscoveryRepositoryInterface $repository
    ) {
    }

    /**
     * Re-denormalize one video's current data into the index, or remove
     * it if it's no longer a published video.
     *
     * @param int $video_id The video post ID.
     */
    public function index(int $video_id): void
    {
        $post = get_post($video_id);

        $is_published_video = $post instanceof WP_Post
            && VideoPostType::POST_TYPE === $post->post_type
            && 'publish' === $post->post_status;

        if (! $is_published_video) {
            $this->repository->delete($video_id);

            return;
        }

        $existing = $this->repository->find($video_id);
        $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id);

        $this->repository->upsert(
            $video_id,
            $post->post_title,
            self::description_for($post),
            self::term_ids($video_id, self::CATEGORY_TAXONOMY),
            self::term_ids($video_id, self::TAG_TAXONOMY),
            Tube_Core_Plugin::instance()->actor_repository()->actor_ids_for_video($video_id),
            Tube_Core_Plugin::instance()->studio_repository()->studio_ids_for_video($video_id),
            $metadata?->duration_seconds,
            $existing->views_total ?? 0,
            $post->post_date_gmt
        );
    }

    /**
     * Remove one video's index row — `tube_core.video.deleted` fires
     * *before* the post is actually gone, so this is a direct delete,
     * not a call to self::index() (which would otherwise still find and
     * re-index the about-to-be-deleted post).
     *
     * @param int $video_id The video post ID.
     */
    public function remove(int $video_id): void
    {
        $this->repository->delete($video_id);
    }

    /**
     * The description to index: the post's own excerpt if it has one,
     * otherwise an auto-generated one from its content — the same
     * fallback WordPress core's own `get_the_excerpt()` uses, computed
     * directly rather than through that function since this runs outside
     * any post-loop/global-`$post` context.
     *
     * @param WP_Post $post The video post.
     */
    private static function description_for(WP_Post $post): ?string
    {
        if ('' !== $post->post_excerpt) {
            return $post->post_excerpt;
        }

        $description = wp_trim_words(wp_strip_all_tags($post->post_content), self::AUTO_EXCERPT_WORDS);

        return '' === $description ? null : $description;
    }

    /**
     * A video's term IDs for one taxonomy, or an empty list if it has
     * none or the lookup fails (a `WP_Error` — treated as "no terms"
     * rather than propagating a fatal into a WordPress hook callback).
     *
     * @param int    $video_id The video post ID.
     * @param string $taxonomy The taxonomy to read term IDs from.
     *
     * @return int[]
     */
    private static function term_ids(int $video_id, string $taxonomy): array
    {
        $term_ids = wp_get_post_terms($video_id, $taxonomy, ['fields' => 'ids']);

        if (! is_array($term_ids)) {
            return [];
        }

        return array_values($term_ids);
    }
}
