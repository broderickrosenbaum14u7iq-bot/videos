<?php
/**
 * The watch page's Like / Save / Share action bar.
 *
 * Every button here is backed by a real system: Like/Save toggle through
 * `Tube_Core\Likes\LikeController`/`Tube_Core\Saves\SaveController`
 * (`assets/js/tube-theme.js`'s "Video actions" section calls the exact
 * `POST /tube/v1/videos/{id}/like`/`/save` endpoints those register);
 * Share uses the real Web Share API where available, falling back to a
 * real clipboard copy of this video's own canonical URL — never a
 * decorative no-op button.
 *
 * Expects, via `get_template_part()`'s `$args`:
 * - `video_id` (int)
 * - `permalink` (string) — this video's canonical URL, for Share.
 * - `title` (string) — this video's title, for the Web Share API's `text`.
 * - `liked` (bool) — the current viewer's initial like state ({@see tube_core_has_liked()}).
 * - `likes_total` (int) — the real current like count ({@see tube_core_likes_total()}).
 * - `saved` (bool) — the current viewer's initial save state ({@see tube_core_has_saved()}).
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $args */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

$tube_theme_has_required_args = isset(
    $args['video_id'],
    $args['permalink'],
    $args['title'],
    $args['liked'],
    $args['likes_total'],
    $args['saved']
);

if (!$tube_theme_has_required_args) {
    return;
}

/** @var int $tube_theme_video_id */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_video_id = $args['video_id'];

/** @var string $tube_theme_permalink */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_permalink = $args['permalink'];

/** @var string $tube_theme_title */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_title = $args['title'];

/** @var bool $tube_theme_liked */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_liked = $args['liked'];

/** @var int $tube_theme_likes_total */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_likes_total = $args['likes_total'];

/** @var bool $tube_theme_saved */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_saved = $args['saved'];

?>
<div
    class="video-actions"
    data-tube-video-actions
    data-like-url="<?php echo esc_url(rest_url('tube/v1/videos/' . $tube_theme_video_id . '/like')); ?>"
    data-save-url="<?php echo esc_url(rest_url('tube/v1/videos/' . $tube_theme_video_id . '/save')); ?>"
    data-rest-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>"
>
    <button
        type="button"
        class="video-actions__btn<?php echo $tube_theme_liked ? ' is-active' : ''; ?>"
        data-tube-like-btn
        aria-pressed="<?php echo $tube_theme_liked ? 'true' : 'false'; ?>"
    >
        <svg class="video-actions__icon" viewBox="0 0 24 24" aria-hidden="true">
            <path
                d="M12 20.5s-7.5-4.6-10-9.3C.4 8 1.8 4.5 5 3.6c2.1-.6 4.2.2 5.5 2
                .5.6.9 1.3 1.5 1.3s1-.7 1.5-1.3c1.3-1.8 3.4-2.6 5.5-2
                3.2.9 4.6 4.4 3 7.6-2.5 4.7-10 9.3-10 9.3z"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linejoin="round"
            />
        </svg>
        <?php $tube_theme_like_label = $tube_theme_liked ? __('Đã thích', 'tube-theme') : __('Thích', 'tube-theme'); ?>
        <span data-tube-like-label><?php echo esc_html($tube_theme_like_label); ?></span>
        <span class="video-actions__count" data-tube-like-count>
            <?php echo esc_html(tube_theme_compact_number($tube_theme_likes_total)); ?>
        </span>
    </button>

    <button
        type="button"
        class="video-actions__btn<?php echo $tube_theme_saved ? ' is-active' : ''; ?>"
        data-tube-save-btn
        aria-pressed="<?php echo $tube_theme_saved ? 'true' : 'false'; ?>"
    >
        <svg class="video-actions__icon" viewBox="0 0 24 24" aria-hidden="true">
            <path
                d="M6 3h12v18l-6-4-6 4V3z"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linejoin="round"
            />
        </svg>
        <span data-tube-save-label>
            <?php echo esc_html($tube_theme_saved ? __('Đã lưu', 'tube-theme') : __('Lưu', 'tube-theme')); ?>
        </span>
    </button>

    <button
        type="button"
        class="video-actions__btn"
        data-tube-share-btn
        data-share-url="<?php echo esc_url($tube_theme_permalink); ?>"
        data-share-title="<?php echo esc_attr($tube_theme_title); ?>"
    >
        <svg class="video-actions__icon" viewBox="0 0 24 24" aria-hidden="true">
            <path
                d="M18 8a3 3 0 100-6 3 3 0 000 6zM6 15a3 3 0 100-6 3 3 0 000 6z
                M18 22a3 3 0 100-6 3 3 0 000 6zM8.6 13.5l6.8 4M15.4 6.5l-6.8 4"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
            />
        </svg>
        <span><?php esc_html_e('Chia sẻ', 'tube-theme'); ?></span>
    </button>
</div>
<div class="video-actions__toast" data-tube-share-toast role="status" aria-live="polite"></div>
