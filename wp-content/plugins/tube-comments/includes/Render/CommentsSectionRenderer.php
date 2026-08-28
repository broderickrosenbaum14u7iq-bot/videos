<?php
/**
 * Renders the watch page's comment section mount point.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Render;

use Tube_Comments\Comments\Repositories\CommentCounterRepository;

/**
 * Renders `single-video.php`'s `tube_comments_render_section()` call
 * (see `includes/template-tags.php`) — Phase 11's comment section.
 *
 * Deliberately renders only a mount point, the initial count, and the
 * composer/sort chrome — never the comment list body itself. Per Phase
 * 31 ("watch-page shell can remain cacheable, comments load
 * dynamically"), the actual list is always fetched client-side
 * (`GET /tube/v1/videos/{id}/comments`), so a cached page shell never
 * goes stale in a way that matters: only the "💬 N" badge could be
 * briefly stale under caching, the same already-accepted tradeoff this
 * project's other cached counters (views, likes) already carry.
 */
final class CommentsSectionRenderer
{
    /**
     * Render the comment section for one video.
     *
     * @param int $video_id The video whose comment section to render.
     */
    public function render(int $video_id): void
    {
        $count         = (new CommentCounterRepository())->get($video_id);
        $duration      = $this->video_duration_seconds($video_id);
        $logged_in     = is_user_logged_in();
        $avatar_url    = $logged_in ? tube_members_get_avatar_url() : '';
        $video_id_text = (string) $video_id;
        $count_text    = (string) $count;
        $duration_text = (string) $duration;
        $list_url      = rest_url('tube/v1/videos/' . $video_id . '/comments');
        $replies_base  = rest_url('tube/v1/comments/');
        ?>
        <div
            class="tube-comments"
            data-tube-comments
            data-video-id="<?php echo esc_attr($video_id_text); ?>"
            data-list-url="<?php echo esc_url($list_url); ?>"
            data-create-url="<?php echo esc_url($list_url); ?>"
            data-replies-url-base="<?php echo esc_url($replies_base); ?>"
            data-video-duration="<?php echo esc_attr($duration_text); ?>"
            data-logged-in="<?php echo $logged_in ? '1' : '0'; ?>"
        >
            <div class="tube-comments__header">
                <h2 class="section-heading tube-comments__title">
                    💬 <?php esc_html_e('Bình luận', 'tube-comments'); ?>
                    <span data-tube-comments-count><?php echo esc_html($count_text); ?></span>
                </h2>
                <div
                    class="tube-comments__sort"
                    role="group"
                    aria-label="<?php echo esc_attr__('Sắp xếp bình luận', 'tube-comments'); ?>"
                >
                    <button type="button" class="tube-comments__sort-btn is-active" data-tube-comments-sort="recent">
                        <?php esc_html_e('Mới nhất', 'tube-comments'); ?>
                    </button>
                    <button type="button" class="tube-comments__sort-btn" data-tube-comments-sort="popular">
                        <?php esc_html_e('Phổ biến', 'tube-comments'); ?>
                    </button>
                </div>
            </div>

            <?php $composer_avatar_url = '' !== $avatar_url ? $avatar_url : tube_members_get_avatar_url(0); ?>
            <form class="tube-comments__composer" data-tube-comments-composer>
                <img
                    class="tube-comments__composer-avatar"
                    src="<?php echo esc_url($composer_avatar_url); ?>"
                    alt=""
                    width="36"
                    height="36"
                >
                <div class="tube-comments__composer-body">
                    <div data-tube-comments-composer-fields>
                        <textarea
                            class="tube-comments__composer-input"
                            name="content"
                            rows="1"
                            maxlength="2000"
                            placeholder="<?php echo esc_attr__('Viết bình luận...', 'tube-comments'); ?>"
                            data-tube-comments-composer-input
                        ></textarea>
                        <div class="tube-comments__composer-actions" data-tube-comments-composer-actions hidden>
                            <button type="button" class="tube-comments__btn-ghost" data-tube-comments-composer-cancel>
                                <?php esc_html_e('Hủy', 'tube-comments'); ?>
                            </button>
                            <button type="submit" class="tube-comments__btn-primary">
                                <?php esc_html_e('Bình luận', 'tube-comments'); ?>
                            </button>
                        </div>
                    </div>
                    <p class="tube-comments__composer-blocked" data-tube-comments-composer-blocked hidden></p>
                </div>
            </form>

            <div class="tube-comments__list" data-tube-comments-list>
                <div class="tube-comments__skeleton" data-tube-comments-skeleton>
                    <div class="tube-comments__skeleton-row"></div>
                    <div class="tube-comments__skeleton-row"></div>
                    <div class="tube-comments__skeleton-row"></div>
                </div>
            </div>

            <button type="button" class="tube-comments__load-more" data-tube-comments-load-more hidden>
                <?php esc_html_e('Xem thêm bình luận', 'tube-comments'); ?>
            </button>
        </div>
        <?php
    }

    /**
     * `wp_enqueue_scripts` callback: this plugin's own comment-UI
     * script and stylesheet.
     */
    public function enqueue_assets(): void
    {
        if (! is_singular('video')) {
            return;
        }

        wp_enqueue_style(
            'tube-comments',
            plugins_url('assets/css/tube-comments.css', TUBE_COMMENTS_FILE),
            [],
            self::asset_version('assets/css/tube-comments.css')
        );

        wp_enqueue_script(
            'tube-comments',
            plugins_url('assets/js/tube-comments.js', TUBE_COMMENTS_FILE),
            [],
            self::asset_version('assets/js/tube-comments.js'),
            true
        );

        wp_localize_script(
            'tube-comments',
            'TubeCommentsConfig',
            [
                'restNonce'       => wp_create_nonce('wp_rest'),
                'isLoggedIn'      => is_user_logged_in(),
                'currentUserId'   => get_current_user_id(),
                'isEmailVerified' => function_exists('tube_members_is_email_verified')
                    ? tube_members_is_email_verified()
                    : true,
            ]
        );
    }

    /**
     * The cache-busting `$ver` for one of this plugin's own CSS/JS
     * files — the file's own mtime, not the fixed `TUBE_COMMENTS_VERSION`
     * plugin-version constant. Mirrors `Tube_Ads\Plugin::asset_version()`
     * (and `tube_theme_asset_version()` in the theme) — same root cause
     * this project already fixed twice elsewhere: a version string that
     * never changes between edits means a browser that already fetched
     * this stylesheet keeps serving that stale copy for up to nginx's
     * 30-day `Cache-Control: max-age` even after the file on disk
     * changes (2026-08-27 compact-mobile pass — this plugin's comment
     * styles were still on the fixed version when that pass's own CSS
     * edits here didn't show up live).
     *
     * @param string $relative_path Path under this plugin's own directory, e.g. `assets/css/tube-comments.css`.
     */
    private static function asset_version(string $relative_path): string
    {
        $path = TUBE_COMMENTS_DIR . '/' . $relative_path;

        if (! file_exists($path)) {
            return TUBE_COMMENTS_VERSION;
        }

        $mtime = filemtime($path);

        return false !== $mtime ? (string) $mtime : TUBE_COMMENTS_VERSION;
    }

    /**
     * This video's real duration, if known — used client-side to refuse
     * to linkify a timestamp beyond the video's own length (Phase 19).
     *
     * @param int $video_id The video to look up.
     */
    private function video_duration_seconds(int $video_id): int
    {
        if (! function_exists('tube_search_get_video')) {
            return 0;
        }

        $row = tube_search_get_video($video_id);

        return null === $row ? 0 : (int) $row->duration_seconds;
    }
}
