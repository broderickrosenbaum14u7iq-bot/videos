<?php
/**
 * The single-video metadata management, custom poster upload, and actor/studio assignment screen.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Video;

use Tube_Admin\Media\ImageUploadException;
use Tube_Admin\Plugin;
use Tube_Admin\Support\Request;
use Tube_Core\Content\VideoPostType;
use Tube_Core\Plugin as Tube_Core_Plugin;

/**
 * The `wp-admin` surface combining three of Phase 10's named deliverables
 * for a single video — video metadata management, actor/studio
 * assignment UI, and custom-poster upload UI — into one screen rather
 * than three near-identical "pick a video, edit one thing" pages. A real
 * editorial workflow edits a video's admin-managed fields together, not
 * through three separate lookups of the same video; see `PHASE-10.md`'s
 * design decisions for the full reasoning.
 *
 * WordPress-coupled throughout and integration/live-tested only, the
 * same split every other screen in this plugin uses.
 */
final class VideoDetailsScreen
{
    /**
     * This screen's menu slug.
     */
    public const SLUG = 'tube-admin-videos';

    /**
     * Nonce action for saving a video's details.
     */
    private const SAVE_NONCE_ACTION = 'tube_admin_save_video_details';

    /**
     * How many actors/studios the assignment picker lists at once. A
     * flat, generous cap rather than its own paginated picker — real
     * catalogs of actors/studios are small relative to the video count
     * this project's 500,000+-video target describes; a picker beyond
     * this size is a distinct future concern (e.g. a searchable
     * AJAX-driven picker), not something to build speculatively now.
     */
    private const PICKER_LIMIT = 300;

    /**
     * How many search results the video picker shows.
     */
    private const SEARCH_LIMIT = 20;

    /**
     * Register this screen's `admin-post.php` action handler. Called
     * once from `Tube_Admin\Plugin::boot()`.
     */
    public static function register_actions(): void
    {
        add_action('admin_post_tube_admin_save_video_details', [self::class, 'handle_save']);
    }

    /**
     * Render the screen: a video picker, or (once a video is selected) its edit form.
     */
    public function render(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'tube-admin'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a GET-only "which video is selected" reader, not a state-changing request.
        $video_id = absint(wp_unslash(Request::string($_GET, 'video_id')));
        $video    = $video_id > 0 ? get_post($video_id) : null;

        if (null === $video || VideoPostType::POST_TYPE !== $video->post_type) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a GET-only search filter, not a state-changing request.
            $search = sanitize_text_field(wp_unslash(Request::string($_GET, 's')));
            $videos = '' === $search ? [] : $this->search_videos($search);

            require __DIR__ . '/views/picker.php';

            return;
        }

        $metadata_repository = Tube_Core_Plugin::instance()->video_metadata_repository();
        $actor_repository    = Tube_Core_Plugin::instance()->actor_repository();
        $studio_repository   = Tube_Core_Plugin::instance()->studio_repository();

        $metadata         = $metadata_repository->find($video_id);
        $all_actors       = $actor_repository->list_all(self::PICKER_LIMIT, 0);
        $all_studios      = $studio_repository->list_all(self::PICKER_LIMIT, 0);
        $assigned_actors  = $actor_repository->actor_ids_for_video($video_id);
        $assigned_studios = $studio_repository->studio_ids_for_video($video_id);

        require __DIR__ . '/views/edit.php';
    }

    /**
     * `admin-post.php` handler: save every field this screen manages for one video.
     */
    public static function handle_save(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'tube-admin'));
        }

        check_admin_referer(self::SAVE_NONCE_ACTION);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce covering this entire request was already verified by check_admin_referer() immediately above.
        $video_id = absint(wp_unslash(Request::string($_POST, 'video_id')));
        $video    = get_post($video_id);

        if (null === $video || VideoPostType::POST_TYPE !== $video->post_type) {
            wp_die(esc_html__('Unknown video.', 'tube-admin'));
        }

        $metadata_repository = Tube_Core_Plugin::instance()->video_metadata_repository();
        $current             = $metadata_repository->find($video_id);
        $poster_image_id     = null === $current ? null : $current->poster_image_id;
        $og_image_id         = null === $current ? null : $current->og_image_id;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified above.
        $thumbnail_time = absint(wp_unslash(Request::string($_POST, 'thumbnail_time_seconds')));
        $metadata_repository->update_thumbnail_time($video_id, $thumbnail_time);

        $poster_image_id = self::apply_image_field('poster_image', 'remove_poster_image', $poster_image_id);
        $og_image_id     = self::apply_image_field('og_image', 'remove_og_image', $og_image_id);

        $metadata_repository->update_images($video_id, $poster_image_id, $og_image_id);

        $actor_ids  = self::selected_ids('actor_ids');
        $studio_ids = self::selected_ids('studio_ids');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified above.
        $new_actor_name = sanitize_text_field(wp_unslash(Request::string($_POST, 'new_actor_name')));

        if ('' !== $new_actor_name) {
            $actor_ids[] = Tube_Core_Plugin::instance()->actor_repository()->create($new_actor_name, null);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified above.
        $new_studio_name = sanitize_text_field(wp_unslash(Request::string($_POST, 'new_studio_name')));

        if ('' !== $new_studio_name) {
            $studio_ids[] = Tube_Core_Plugin::instance()->studio_repository()->create($new_studio_name, null, null);
        }

        Plugin::instance()->assignment_service()->set_actors_for_video($video_id, $actor_ids);
        Plugin::instance()->assignment_service()->set_studios_for_video($video_id, $studio_ids);

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'     => self::SLUG,
                    'video_id' => $video_id,
                    'saved'    => '1',
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
     * Apply an uploaded-file/removal-checkbox pair for one image field
     * (poster or OG) and return the ID that should end up persisted.
     * Best-effort: a delete failure for a superseded/removed image is
     * silently accepted (the field's new value is already correct
     * either way) — the same posture `PosterUploadService::replace()`
     * already documents for its own delete step.
     *
     * @param string   $file_field    The `$_FILES` key for this field.
     * @param string   $remove_field  The `$_POST` checkbox key requesting removal.
     * @param int|null $current_id    The image ID currently stored for this field.
     */
    private static function apply_image_field(string $file_field, string $remove_field, ?int $current_id): ?int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce for this whole request already verified in handle_save().
        $files      = $_FILES[ $file_field ] ?? null;
        $has_upload = is_array($files) && isset($files['tmp_name']) && '' !== $files['tmp_name'];

        if ($has_upload) {
            require_once ABSPATH . 'wp-admin/includes/file.php';

            /** @var array{name?: string, type?: string, tmp_name?: string, size?: int, error?: int} $files */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
            $upload = wp_handle_upload($files, ['test_form' => false]);

            if (! isset($upload['file']) || ! is_string($upload['file'])) {
                return $current_id;
            }

            $file_path = $upload['file'];
            $filename  = wp_basename($file_path);

            try {
                return Plugin::instance()->poster_upload_service()->replace(
                    $file_path,
                    $filename,
                    $current_id,
                    static function (int $new_id): void {
                        // Persistence for both image fields happens together
                        // in handle_save()'s single update_images() call
                        // after both fields are resolved -- nothing to do here.
                    }
                );
            } catch (ImageUploadException $exception) {
                return $current_id;
            } finally {
                wp_delete_file($file_path);
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce for this whole request already verified in handle_save().
        $remove_requested = isset($_POST[ $remove_field ]);

        if ($remove_requested && null !== $current_id) {
            try {
                Plugin::instance()->image_uploader()->delete($current_id);
            } catch (ImageUploadException $exception) {
                // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- best-effort delete; failure intentionally ignored, see this method's own docblock.
                unset($exception);
            }

            return null;
        }

        return $current_id;
    }

    /**
     * Read a list of positive integer IDs from a `$_POST` array field.
     *
     * @param string $field The `$_POST` key.
     *
     * @return int[]
     */
    private static function selected_ids(string $field): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce for this whole request already verified in handle_save().
        $raw = $_POST[ $field ] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $unslashed = wp_unslash($raw);

        $ids = array_map(
            static fn (mixed $value): int => absint(is_scalar($value) ? $value : 0),
            $unslashed
        );

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }
}
