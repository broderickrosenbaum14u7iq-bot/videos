<?php
/**
 * Template-tag wrappers the theme/tube-members call directly.
 *
 * No `ABSPATH` guard here — `tube-comments.php` already exits before
 * `require_once`-ing this file (the same convention every other tube-*
 * plugin's own `includes/template-tags.php` already documents).
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

use Tube_Comments\Render\CommentsSectionRenderer;

/**
 * Render the comment section for one video — called from
 * `single-video.php` inside `.watch-layout__main` (Phase 11/Phase 36:
 * "Comments belong inside .watch-layout__main, not as a sibling after
 * .watch-layout__sidebar"). Guarded with `function_exists()` at the call
 * site, so this plugin being deactivated never breaks the watch page.
 *
 * @param int $video_id The video whose comment section to render.
 */
function tube_comments_render_section(int $video_id): void
{
    (new CommentsSectionRenderer())->render($video_id);
}

/**
 * Render the "Bình luận của tôi" mount point on tube-members' frontend
 * account page (Phase 9) — called via `function_exists()` from
 * `tube-members`' `account-page.php` template, the same cross-plugin
 * hook shape as {@see tube_comments_render_section()}. Ensures this
 * plugin's own script/style are enqueued even though the account page
 * is not a `is_singular('video')` request (`CommentsSectionRenderer::
 * enqueue_assets()`'s own gate would otherwise skip it there).
 */
function tube_comments_render_my_comments_mount(): void
{
    wp_enqueue_style(
        'tube-comments',
        plugins_url('assets/css/tube-comments.css', TUBE_COMMENTS_FILE),
        [],
        TUBE_COMMENTS_VERSION
    );

    wp_enqueue_script(
        'tube-comments',
        plugins_url('assets/js/tube-comments.js', TUBE_COMMENTS_FILE),
        [],
        TUBE_COMMENTS_VERSION,
        true
    );

    wp_localize_script(
        'tube-comments',
        'TubeCommentsConfig',
        [
            'restNonce'     => wp_create_nonce('wp_rest'),
            'isLoggedIn'    => is_user_logged_in(),
            'currentUserId' => get_current_user_id(),
        ]
    );

    $mine_url = rest_url('tube/v1/comments/mine');
    ?>
    <div class="tube-comments-mine" data-tube-comments-mine data-mine-url="<?php echo esc_url($mine_url); ?>">
        <p class="tube-comments-mine__loading"><?php esc_html_e('Đang tải...', 'tube-comments'); ?></p>
    </div>
    <?php
}
