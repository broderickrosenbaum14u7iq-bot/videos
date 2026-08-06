<?php
/**
 * Bulk actor/studio assignment across several videos at once.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Bulk;

use Tube_Admin\Plugin;
use Tube_Admin\Support\Request;
use Tube_Core\Content\VideoPostType;
use Tube_Core\Plugin as Tube_Core_Plugin;

/**
 * The `wp-admin` surface for bulk actor/studio assignment: search for
 * videos, select several, add or remove one or more actors/studios
 * across all of them at once — backed by
 * `ActorRepository`/`StudioRepository::bulk_add()`/`bulk_remove()`
 * (Phase 10, §19.8-compliant multi-row writes).
 *
 * Deliberately does **not** include a "bulk re-import/reprocess"
 * trigger, despite `SESSION_START.md`'s tentative "possibly" note: the
 * import pipeline (`wp_tube_import_queue`) only has a concept of
 * enqueueing new source items keyed by `source_key`
 * (`ImportQueueRepository::bulk_enqueue()`) — there is no real
 * "reprocess this already-imported video" mechanism anywhere in the
 * architecture to trigger, and building a UI over a mechanism that
 * doesn't exist would be exactly the placeholder/mock content
 * `DEVELOPMENT_RULES.md` prohibits. The Import Dashboard's "Process Next
 * Batch Now" already covers the real bulk-processing action that does
 * exist. See `PHASE-10.md`'s design decisions.
 *
 * WordPress-coupled throughout and integration/live-tested only, the
 * same split every other screen in this plugin uses.
 */
final class BulkToolsScreen
{
    /**
     * This screen's menu slug.
     */
    public const SLUG = 'tube-admin-bulk';

    /**
     * Nonce action for the bulk actor/studio assignment form.
     */
    private const ASSIGN_NONCE_ACTION = 'tube_admin_bulk_assign';

    /**
     * How many actors/studios the picker lists at once. Same reasoning
     * as `VideoDetailsScreen::PICKER_LIMIT`.
     */
    private const PICKER_LIMIT = 300;

    /**
     * How many search results the video picker shows.
     */
    private const SEARCH_LIMIT = 50;

    /**
     * Register this screen's `admin-post.php` action handler. Called
     * once from `Tube_Admin\Plugin::boot()`.
     */
    public static function register_actions(): void
    {
        add_action('admin_post_tube_admin_bulk_assign', [self::class, 'handle_assign']);
    }

    /**
     * Render the screen.
     */
    public function render(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'tube-admin'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a GET-only search filter, not a state-changing request.
        $search = sanitize_text_field(wp_unslash(Request::string($_GET, 's')));
        $videos = '' === $search ? [] : $this->search_videos($search);

        $all_actors  = Tube_Core_Plugin::instance()->actor_repository()->list_all(self::PICKER_LIMIT, 0);
        $all_studios = Tube_Core_Plugin::instance()->studio_repository()->list_all(self::PICKER_LIMIT, 0);

        require __DIR__ . '/views/bulk.php';
    }

    /**
     * `admin-post.php` handler: add or remove the selected actors/studios
     * across every selected video.
     */
    public static function handle_assign(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'tube-admin'));
        }

        check_admin_referer(self::ASSIGN_NONCE_ACTION);

        $video_ids  = self::posted_ids('video_ids');
        $actor_ids  = self::posted_ids('actor_ids');
        $studio_ids = self::posted_ids('studio_ids');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce covering this entire request was already verified by check_admin_referer() immediately above.
        $mode = sanitize_key(wp_unslash(Request::string($_POST, 'bulk_mode', 'add')));

        $actor_affected  = 0;
        $studio_affected = 0;

        if ([] !== $video_ids) {
            $service = Plugin::instance()->assignment_service();

            if ([] !== $actor_ids) {
                $actor_affected = 'remove' === $mode
                    ? $service->bulk_remove_actors($video_ids, $actor_ids)
                    : $service->bulk_add_actors($video_ids, $actor_ids);
            }

            if ([] !== $studio_ids) {
                $studio_affected = 'remove' === $mode
                    ? $service->bulk_remove_studios($video_ids, $studio_ids)
                    : $service->bulk_add_studios($video_ids, $studio_ids);
            }
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'    => self::SLUG,
                    'result'  => '1',
                    'actors'  => (string) $actor_affected,
                    'studios' => (string) $studio_affected,
                ],
                admin_url('admin.php')
            )
        );

        exit;
    }

    /**
     * Search published/draft videos by title.
     *
     * @param string $search The search term.
     *
     * @return list<array{id: int, title: string}>
     */
    private function search_videos(string $search): array
    {
        $query = new \WP_Query(
            [
                's'              => $search,
                'post_type'      => VideoPostType::POST_TYPE,
                'post_status'    => ['publish', 'draft', 'pending', 'future'],
                'posts_per_page' => self::SEARCH_LIMIT,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'no_found_rows'  => true,
                'fields'         => 'ids',
            ]
        );

        // 'fields' => 'ids' deliberately skips WP_Query's usual post-object
        // cache priming, so get_the_title() per result below would
        // otherwise be one query per row -- batch-hydrate explicitly
        // first, the same _prime_post_caches() technique
        // Tube_Seo\Sitemap\SitemapGenerator already established.
        _prime_post_caches(array_filter($query->posts, 'is_int'), false, false);

        $result = [];

        foreach ($query->posts as $post_id) {
            if (! is_int($post_id)) {
                continue;
            }

            $title       = get_the_title($post_id);
            $post_id_str = strval($post_id);

            $result[] = [
                'id'    => $post_id,
                'title' => '' === $title ? "#{$post_id_str}" : $title,
            ];
        }

        return $result;
    }

    /**
     * Read a list of positive integer IDs from a `$_POST` array field.
     *
     * @param string $field The `$_POST` key.
     *
     * @return int[]
     */
    private static function posted_ids(string $field): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce for this whole request already verified in handle_assign().
        $raw       = $_POST[ $field ] ?? [];
        $raw       = is_array($raw) ? $raw : [];
        $unslashed = wp_unslash($raw);

        $ids = array_map(
            static fn (mixed $value): int => absint(is_scalar($value) ? $value : 0),
            $unslashed
        );

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }
}
