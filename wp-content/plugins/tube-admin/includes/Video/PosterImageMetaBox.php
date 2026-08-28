<?php
/**
 * The Poster Image meta box on the native `video` edit screen.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Video;

use Tube_Admin\Support\Request;
use Tube_Core\Content\VideoPostType;
use Tube_Core\Plugin as Tube_Core_Plugin;
use WP_Post;

/**
 * Exposes and persists `wp_tube_video_metadata.poster_image_id` directly on
 * WordPress's own native `Videos → Add New`/`Edit Video` screen
 * (`post-new.php`/`post.php` for the `video` post type), via a standard
 * `add_meta_box()` + `save_post_video` pair — the same canonical storage
 * `Tube_Core\Video\Repositories\VideoMetadataRepository` already owns
 * (`update_images()`), not a second/parallel poster storage system.
 *
 * ADR-0001's 2026-08-25 addendum's own incident is exactly why this class
 * exists: WordPress's native Featured Image box (`VideoPostType`'s
 * removed `thumbnail` support) sat on this same screen, relabeled
 * "Poster Image," and silently absorbed real edits into `_thumbnail_id`
 * postmeta — a field nothing in this codebase reads. Rather than leave
 * that gap and send an editor to a second screen
 * (`Tube_Admin\Video\VideoDetailsScreen`) just to set a poster, this meta
 * box **is** the one real "Poster Image" control, wired to the actual
 * canonical field, on the screen an editor is already on. `poster_image_id`
 * is no longer editable from `VideoDetailsScreen` at all (read-only
 * display + a link here) — one obvious editing surface per field, the
 * same treatment `Tube_Admin\Video\StreamUidMetaBox` already established
 * for `cf_stream_uid`. `og_image_id` (a genuinely different field — the
 * social-share preview image, not this screen's concern) remains
 * `VideoDetailsScreen`'s own, unaffected.
 *
 * The `wp.media()` wiring itself (`assets/js/media-picker.js`) and the
 * picker markup (`views/media-picker.php`) are shared verbatim with
 * `VideoDetailsScreen`'s own poster/OG-image pickers — the same
 * attachment-ID-hidden-input contract, so `self::save()` needs no
 * poster-specific JS of its own.
 *
 * A video with no `wp_tube_video_metadata` row yet (no Cloudflare Stream
 * UID set — see `StreamUidMetaBox`) has nothing to attach a poster to;
 * `self::render()` says so instead of showing a picker with nowhere to
 * save, and `self::save()` silently no-ops in that case (not an error —
 * `StreamUidMetaBox`'s own notice on the same screen already explains
 * why). `StreamUidMetaBox::register()` runs before `self::register()`
 * (see `Tube_Admin\Plugin::boot()`), so on a single "Add New" submission
 * with both fields filled in, the metadata row `StreamUidMetaBox::save()`
 * creates already exists by the time `self::save()` runs on the same
 * `save_post_video` hook — verified via
 * `VideoMetadataRepository`'s own documented same-request
 * create-then-find cache-invalidation contract.
 *
 * No event is dispatched directly from `self::save()` —
 * `Tube_Core\Events\VideoLifecycleEvents` already listens on the same
 * `save_post_video` hook (registered independently in tube-core) and
 * dispatches `VIDEO_UPDATED`/`VIDEO_CREATED` for every real save
 * regardless of which meta box changed what, so a second explicit
 * dispatch here would be redundant. The homepage/frontend poster itself
 * needs no event at all to update: `tube_player_get_image_html()`
 * resolves `poster_image_id` fresh from canonical storage on every
 * render — it is never cached or denormalized into the search index —
 * so a save is visible immediately regardless.
 */
final class PosterImageMetaBox
{
    /**
     * Nonce action covering this meta box's own field.
     */
    private const NONCE_ACTION = 'tube_admin_video_poster_image';

    /**
     * The nonce field's `$_POST` name.
     */
    private const NONCE_NAME = 'tube_admin_video_poster_image_nonce';

    /**
     * The poster attachment ID input's `$_POST` name — matches
     * `views/media-picker.php`'s existing `poster_image_id` field-name
     * convention (already used by `VideoDetailsScreen`), so the shared
     * picker partial needs no per-caller field-name translation.
     */
    private const FIELD_NAME = 'poster_image_id';

    /**
     * Register this meta box's hooks. Called once from `Tube_Admin\Plugin::boot()`,
     * after `StreamUidMetaBox::register()` — see this class's own docblock for why the order matters.
     */
    public static function register(): void
    {
        add_action('add_meta_boxes_video', [self::class, 'add']);
        add_action('save_post_video', [self::class, 'save']);
    }

    /**
     * Register the meta box itself, and its dependencies. Called on `add_meta_boxes_video`.
     */
    public static function add(): void
    {
        // The native post edit screen does not guarantee the media modal
        // is already enqueued for every post type/context — call
        // explicitly, the same defensive posture
        // `VideoDetailsScreen::render()` already takes for its own screen.
        wp_enqueue_media();

        wp_enqueue_script(
            'tube-admin-media-picker',
            plugins_url('assets/js/media-picker.js', TUBE_ADMIN_FILE),
            [],
            TUBE_ADMIN_VERSION,
            true
        );

        add_meta_box(
            'tube_admin_poster_image',
            __('Poster Image', 'tube-admin'),
            [self::class, 'render'],
            VideoPostType::POST_TYPE,
            'side',
            'high'
        );
    }

    /**
     * Render the meta box: a nonce field, then either the picker (a
     * metadata row already exists) or an explanatory notice (it doesn't yet).
     *
     * @param WP_Post $post The video post currently being edited.
     */
    public static function render(WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($post->ID);

        if (null === $metadata) {
            ?>
            <p class="description">
                <?php
                esc_html_e(
                    'Set a Cloudflare Stream UID above first, then Publish/Update — a poster can only be attached to a video that already has one.', // phpcs:ignore Generic.Files.LineLength.TooLong -- a single translatable string literal (WordPress.WP.I18n.NonSingularStringLiteralText forbids splitting it via concatenation).
                    'tube-admin'
                );
                ?>
            </p>
            <?php

            return;
        }

        $tube_admin_field_name    = self::FIELD_NAME;
        $tube_admin_field_value   = $metadata->poster_image_id;
        $tube_admin_unsaved_label = __('Not saved yet — click Publish/Update.', 'tube-admin');

        require __DIR__ . '/views/media-picker.php';
    }

    /**
     * Persist the submitted poster attachment ID. Called on `save_post_video`.
     *
     * @param int $post_id The video post ID.
     */
    public static function save(int $post_id): void
    {
        if (
            ! isset($_POST[ self::NONCE_NAME ])
            || ! is_string($_POST[ self::NONCE_NAME ])
            || ! wp_verify_nonce(wp_unslash($_POST[ self::NONCE_NAME ]), self::NONCE_ACTION)
        ) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        $metadata_repository = Tube_Core_Plugin::instance()->video_metadata_repository();
        $current             = $metadata_repository->find($post_id);

        if (null === $current) {
            // No Stream UID was set on this same submission (or ever) —
            // nothing to attach a poster to. Silent no-op, not an error:
            // self::render()'s own notice on this same screen already
            // explains why, and StreamUidMetaBox's field is right above
            // this one.
            return;
        }

        $poster_image_id = self::resolve_poster_image_id($current->poster_image_id);

        if ($poster_image_id === $current->poster_image_id) {
            // Unchanged — skip the write entirely rather than bumping
            // updated_at on every ordinary save that didn't touch the poster.
            return;
        }

        $metadata_repository->update_images($post_id, $poster_image_id, $current->og_image_id);
    }

    /**
     * Resolve the submitted poster attachment ID — the hidden input
     * `views/media-picker.php`'s JS writes to, either a real attachment ID
     * or '' (no image selected/the picker's "Remove" button was pressed).
     *
     * @param int|null $current_id The ID currently stored, kept if the submission is invalid.
     */
    private static function resolve_poster_image_id(?int $current_id): ?int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce for this whole request already verified in self::save().
        $raw = trim(wp_unslash(Request::string($_POST, self::FIELD_NAME)));

        if ('' === $raw) {
            return null;
        }

        $attachment_id = absint($raw);

        if (0 === $attachment_id || ! wp_attachment_is_image($attachment_id)) {
            return $current_id;
        }

        return $attachment_id;
    }
}
