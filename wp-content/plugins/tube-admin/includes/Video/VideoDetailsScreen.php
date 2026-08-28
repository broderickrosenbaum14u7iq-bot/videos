<?php
/**
 * The single-video metadata management, custom poster upload, and actor/studio assignment screen.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Video;

use Tube_Admin\Plugin;
use Tube_Admin\Support\Request;
use Tube_Core\Content\VideoPostType;
use Tube_Core\Plugin as Tube_Core_Plugin;

/**
 * The `wp-admin` surface combining thumbnail-offset management, OG-image
 * selection (WordPress Media Library, per ADR-0001), and actor/studio
 * assignment for a single video — into one screen rather than several
 * near-identical "pick a video, edit one thing" pages. A real editorial
 * workflow edits a video's admin-managed fields together, not through
 * separate lookups of the same video; see `PHASE-10.md`'s design
 * decisions for the original reasoning, extended by ADR-0001.
 *
 * Neither the Cloudflare Stream UID nor the poster image is managed
 * here — both live on WordPress's own native "Videos → Add New"/"Edit
 * Video" screen instead (`Tube_Admin\Video\StreamUidMetaBox`,
 * `Tube_Admin\Video\PosterImageMetaBox` — the latter since the ADR's
 * 2026-08-25 addendum), so an editor sets them exactly where they're
 * already creating/editing the video, without a second trip to a
 * separate `wp-admin` page. This screen only displays the Stream UID and
 * poster (both read-only, with a link to the native edit screen) since
 * every field this screen *does* manage (OG-image override, thumbnail
 * offset, actor/studio assignment) is meaningless without a
 * `wp_tube_video_metadata` row already existing — which the Stream UID
 * meta box is what creates, on the native screen. `og_image_id` is kept
 * here, not moved: it is a distinct field (the social-share preview
 * image, not the video-card poster) the native screen's required-field
 * list was never asked to include.
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

        // Only the edit view needs the media modal (OG-image picker,
        // ADR-0001) — the picker view above never reaches here.
        wp_enqueue_media();
        wp_enqueue_script(
            'tube-admin-media-picker',
            plugins_url('assets/js/media-picker.js', TUBE_ADMIN_FILE),
            [],
            TUBE_ADMIN_VERSION,
            true
        );

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

        if (null === $current) {
            // No wp_tube_video_metadata row yet — the administrator hasn't
            // set this video's Cloudflare Stream UID on its native edit
            // screen (Tube_Admin\Video\StreamUidMetaBox) yet, so there is
            // no row to attach a poster/OG override or thumbnail offset
            // to. Redirect back with an explanatory notice rather than
            // silently no-op every write below.
            self::redirect_with_error($video_id, 'no_stream_uid_yet');
        }

        // poster_image_id is intentionally never touched here — it is
        // read-only on this screen (Tube_Admin\Video\PosterImageMetaBox on
        // the native Videos → Add New/Edit Video screen is the only
        // writer, since the ADR's 2026-08-25 addendum), so whatever it
        // currently is is preserved unchanged.
        $og_image_id = $current->og_image_id;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified above.
        $thumbnail_time = absint(wp_unslash(Request::string($_POST, 'thumbnail_time_seconds')));
        $metadata_repository->update_thumbnail_time($video_id, $thumbnail_time);

        $og_image_id = self::resolve_image_field('og_image_id', $og_image_id);

        $metadata_repository->update_images($video_id, $current->poster_image_id, $og_image_id);

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
     * Resolve one poster/OG-image field's submitted WordPress attachment
     * ID (ADR-0001) — the hidden input `Tube_Admin\Video\views\edit.php`'s
     * media-picker JS writes to, either a real attachment ID or '' (no
     * image selected/picker's "Remove" button pressed). An unattached
     * media library upload is deliberately left as-is (never deleted)
     * when replaced or cleared here: unlike the old Cloudflare Images
     * flow, a WordPress attachment is a first-class Media Library item an
     * editor may reuse elsewhere or manage from Media → Library directly,
     * not a purpose-specific upload this screen exclusively owns the
     * lifecycle of.
     *
     * @param string   $field      The `$_POST` field name (`poster_image_id` or `og_image_id`).
     * @param int|null $current_id The ID currently stored for this field, kept if the submission is invalid.
     */
    private static function resolve_image_field(string $field, ?int $current_id): ?int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce for this whole request already verified in handle_save().
        $raw = trim(wp_unslash(Request::string($_POST, $field)));

        if ('' === $raw) {
            return null;
        }

        $attachment_id = absint($raw);

        if (0 === $attachment_id || ! wp_attachment_is_image($attachment_id)) {
            return $current_id;
        }

        return $attachment_id;
    }

    /**
     * Redirect back to this video's edit form with an error notice, then exit.
     *
     * @param int    $video_id   The video post ID.
     * @param string $error_code A short code `views/edit.php` maps to a translated message.
     */
    private static function redirect_with_error(int $video_id, string $error_code): never
    {
        wp_safe_redirect(
            add_query_arg(
                [
                    'page'     => self::SLUG,
                    'video_id' => $video_id,
                    'error'    => $error_code,
                ],
                admin_url('admin.php')
            )
        );

        exit;
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
