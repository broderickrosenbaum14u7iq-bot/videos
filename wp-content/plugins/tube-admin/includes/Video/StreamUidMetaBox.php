<?php
/**
 * The video-source meta box (Cloudflare Stream UID or R2/direct-MP4 URL) on the native `video` edit screen.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Video;

use Tube_Admin\Support\Request;
use Tube_Core\Content\VideoPostType;
use Tube_Core\Plugin as Tube_Core_Plugin;
use Tube_Core\Video\CfStreamStatus;
use Tube_Core\Video\VideoMetadata;
use Tube_Core\Video\VideoSource;
use WP_Post;

/**
 * Exposes and persists a video's source — Cloudflare Stream UID or R2/
 * direct-MP4 object key — directly on WordPress's own native
 * `Videos → Add New`/`Edit Video` screen (`post-new.php`/`post.php` for
 * the `video` post type), via a standard `add_meta_box()` +
 * `save_post_video` pair, extended (not replaced) from the original
 * Stream-only meta box to add R2 support alongside it: the same
 * `wp_tube_video_metadata` row `Tube_Core\Video\Repositories\VideoMetadataRepository`
 * already owns now holds either source, never a second/parallel table or
 * meta key. `Tube_Admin\Video\VideoDetailsScreen` previously required a
 * *second* trip to a separate `wp-admin` screen just to set this field;
 * that requirement is removed by putting the field where an editor
 * already is when creating/editing a video.
 *
 * **Source selection**: a radio choice between the two
 * `Tube_Core\Video\VideoSource` cases, defaulting to whichever source an
 * existing video already has (or `CloudflareStream`, unchanged from
 * before this class supported a second source, for a brand-new video).
 * Only the field matching the submitted source is ever read/validated/
 * persisted — the other field's submitted value (if the form happened to
 * include stale data from a prior state) is silently ignored, never
 * partially applied.
 *
 * **Cloudflare Stream validation posture**: unchanged from before R2
 * support existed — see the Stream-specific paragraphs in
 * {@see self::save_stream()}'s docblock.
 *
 * **R2 validation posture**: the submitted value (a full
 * `TUBE_CORE_R2_MEDIA_BASE_URL`-hosted URL or a bare object key) is
 * normalized and validated against the *real* configured R2 domain
 * (`Tube_Core\Video\R2\R2MediaUrlNormalizer` — rejects anything that
 * doesn't resolve to that one trusted host, the SSRF protection this
 * feature's own security requirements call for) before anything is
 * persisted. A duplicate object key (already owned by a different video)
 * is rejected the same way a duplicate Stream UID already is. On a
 * normalized, non-duplicate key, a live HEAD request
 * (`Tube_Core\Video\R2\R2VideoValidator`) decides readiness *synchronously*
 * — `Ready` if the resource is reachable and video-like, `Error`
 * otherwise — never `Pending`/`Processing` (there is no Cloudflare-style
 * encoding pipeline for a direct file to sit in), which is specifically
 * what keeps a newly-saved R2 video from ever showing the Stream-only
 * "Video đang được xử lý" processing message. Duration for this source
 * has no reliable zero-bandwidth automatic mechanism (a HEAD request
 * never carries it, and probing the actual bytes to read it would mean
 * downloading a potentially multi-gigabyte file) — an administrator-
 * entered duration field is the chosen mechanism (simplest, always
 * reliable, zero bandwidth cost), optional so publishing is never
 * blocked on it, written through the exact same canonical
 * `duration_seconds` column/`StreamStatusUpdater` write path Stream's own
 * duration already uses, per this feature's own "one canonical duration
 * field for both sources" requirement.
 */
final class StreamUidMetaBox
{
    /**
     * Nonce action covering this meta box's own fields.
     */
    private const NONCE_ACTION = 'tube_admin_video_stream_uid';

    /**
     * The nonce field's `$_POST` name.
     */
    private const NONCE_NAME = 'tube_admin_video_stream_uid_nonce';

    /**
     * The source-choice radio input's `$_POST` name.
     */
    private const SOURCE_FIELD_NAME = 'tube_admin_video_source';

    /**
     * The Stream UID input's `$_POST` name.
     */
    private const FIELD_NAME = 'tube_admin_cf_stream_uid';

    /**
     * The R2 URL/object-key input's `$_POST` name.
     */
    private const R2_FIELD_NAME = 'tube_admin_r2_source';

    /**
     * The R2 admin-entered duration input's `$_POST` name.
     */
    private const R2_DURATION_FIELD_NAME = 'tube_admin_r2_duration_seconds';

    /**
     * How long a rejected-duplicate submission's attempted value/error
     * survives, in seconds — only needs to bridge one redirect round trip
     * (WordPress's own post-save-then-redirect-to-edit-screen flow), not
     * to persist meaningfully longer.
     */
    private const PENDING_TTL_SECONDS = 60;

    /**
     * Nonce action covering the manual "Resync from Cloudflare" link.
     */
    private const RESYNC_NONCE_ACTION = 'tube_admin_resync_stream_metadata';

    /**
     * Register this meta box's hooks. Called once from `Tube_Admin\Plugin::boot()`.
     */
    public static function register(): void
    {
        add_action('add_meta_boxes_video', [self::class, 'add']);
        add_action('save_post_video', [self::class, 'save']);
        add_action('admin_post_tube_admin_resync_stream_metadata', [self::class, 'handle_resync']);
        add_action('admin_notices', [self::class, 'render_resync_notice']);
    }

    /**
     * Register the meta box itself. Called on `add_meta_boxes_video`.
     */
    public static function add(): void
    {
        add_meta_box(
            'tube_admin_stream_uid',
            __('Video Source', 'tube-admin'),
            [self::class, 'render'],
            VideoPostType::POST_TYPE,
            'side',
            'high'
        );
    }

    /**
     * Render the meta box: a nonce field, the source-choice radio, and
     * whichever sub-field's state/error is relevant.
     *
     * @param WP_Post $post The video post currently being edited.
     */
    public static function render(WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($post->ID);
        $source   = $metadata->source ?? VideoSource::CloudflareStream;

        $pending = get_transient(self::pending_key($post->ID));
        delete_transient(self::pending_key($post->ID));

        ?>
        <p>
            <label>
                <input
                    type="radio"
                    name="<?php echo esc_attr(self::SOURCE_FIELD_NAME); ?>"
                    value="<?php echo esc_attr(VideoSource::CloudflareStream->value); ?>"
                    data-tube-admin-video-source-choice
                    <?php checked(VideoSource::CloudflareStream->value, $source->value); ?>
                />
                <?php esc_html_e('Cloudflare Stream', 'tube-admin'); ?>
            </label>
            <br />
            <label>
                <input
                    type="radio"
                    name="<?php echo esc_attr(self::SOURCE_FIELD_NAME); ?>"
                    value="<?php echo esc_attr(VideoSource::R2Mp4->value); ?>"
                    data-tube-admin-video-source-choice
                    <?php checked(VideoSource::R2Mp4->value, $source->value); ?>
                />
                <?php esc_html_e('Cloudflare R2 / MP4', 'tube-admin'); ?>
            </label>
        </p>

        <div data-tube-admin-video-source="<?php echo esc_attr(VideoSource::CloudflareStream->value); ?>">
            <?php self::render_stream_fields($post, $metadata, $pending); ?>
        </div>
        <div data-tube-admin-video-source="<?php echo esc_attr(VideoSource::R2Mp4->value); ?>">
            <?php self::render_r2_fields($post, $metadata, $pending); ?>
        </div>

        <script>
        (function () {
            var wrap = document.currentScript.parentElement;
            var radios = wrap.querySelectorAll('[data-tube-admin-video-source-choice]');
            var panels = wrap.querySelectorAll('[data-tube-admin-video-source]');

            function sync() {
                var checked = wrap.querySelector('[data-tube-admin-video-source-choice]:checked');
                var active = checked ? checked.value : '';

                panels.forEach(function (panel) {
                    panel.hidden = panel.getAttribute('data-tube-admin-video-source') !== active;
                });
            }

            radios.forEach(function (radio) {
                radio.addEventListener('change', sync);
            });

            sync();
        })();
        </script>
        <?php
    }

    /**
     * The Cloudflare Stream sub-fields — unchanged from before R2 support existed.
     *
     * @param WP_Post            $post     The video post currently being edited.
     * @param VideoMetadata|null $metadata The video's current metadata, if any.
     * @param mixed              $pending  Whatever get_transient() returned for this video's pending-submission
     *                                     key — checked with is_array()/isset() below rather than trusted by shape,
     *                                     since nothing guarantees a transient's stored value at read time.
     */
    private static function render_stream_fields(WP_Post $post, ?VideoMetadata $metadata, mixed $pending): void
    {
        $value = null === $metadata || VideoSource::CloudflareStream !== $metadata->source
            ? ''
            : ($metadata->cf_stream_uid ?? '');

        if (
            is_array($pending) && isset($pending['field'], $pending['value'], $pending['error'])
            && self::FIELD_NAME === $pending['field'] && is_string($pending['value'])
        ) {
            $value = $pending['value'];
            ?>
            <div class="notice notice-error inline" style="margin: 0 0 8px;">
                <p>
                    <?php
                    esc_html_e(
                        'That Cloudflare Stream UID is already assigned to another video. Not saved.',
                        'tube-admin'
                    );
                    ?>
                </p>
            </div>
            <?php
        }
        ?>
        <p>
            <label for="<?php echo esc_attr(self::FIELD_NAME); ?>">
                <?php esc_html_e('Cloudflare Stream UID', 'tube-admin'); ?>
            </label>
            <input
                type="text"
                id="<?php echo esc_attr(self::FIELD_NAME); ?>"
                name="<?php echo esc_attr(self::FIELD_NAME); ?>"
                class="widefat"
                value="<?php echo esc_attr($value); ?>"
            />
        </p>
        <p class="description">
            <?php esc_html_e('The Cloudflare Stream video ID this video plays. Must be unique.', 'tube-admin'); ?>
        </p>
        <?php
        if (null !== $metadata && VideoSource::CloudflareStream === $metadata->source) :
            ?>
            <p class="description">
                <?php
                echo esc_html(
                    sprintf(
                    /* translators: %s: encoding status. */
                        __('Encoding status: %s', 'tube-admin'),
                        $metadata->cf_status->value
                    )
                );
                ?>
                <br />
                <?php
                echo esc_html(
                    null === $metadata->duration_seconds
                        ? __('Duration: unknown (not synced from Cloudflare yet)', 'tube-admin')
                        /* translators: %d: duration in seconds. */
                        : sprintf(__('Duration: %d seconds', 'tube-admin'), $metadata->duration_seconds)
                );
                ?>
            </p>
            <p>
                <?php
                $resync_url = wp_nonce_url(
                    add_query_arg(
                        [
                            'action'   => 'tube_admin_resync_stream_metadata',
                            'video_id' => $post->ID,
                        ],
                        admin_url('admin-post.php')
                    ),
                    self::RESYNC_NONCE_ACTION
                );
                ?>
                <a href="<?php echo esc_url($resync_url); ?>" class="button">
                    <?php esc_html_e('Resync from Cloudflare', 'tube-admin'); ?>
                </a>
            </p>
            <?php
        endif;
    }

    /**
     * The R2/direct-MP4 sub-fields.
     *
     * @param WP_Post            $post     The video post currently being edited.
     * @param VideoMetadata|null $metadata The video's current metadata, if any.
     * @param mixed              $pending  Whatever get_transient() returned for this video's pending-submission
     *                                     key — checked with is_array()/isset() below rather than trusted by shape,
     *                                     since nothing guarantees a transient's stored value at read time.
     */
    private static function render_r2_fields(WP_Post $post, ?VideoMetadata $metadata, mixed $pending): void
    {
        unset($post);

        $r2_metadata    = null !== $metadata && VideoSource::R2Mp4 === $metadata->source ? $metadata : null;
        $value          = $r2_metadata->r2_object_key ?? '';
        $duration_value = null === $r2_metadata || null === $r2_metadata->duration_seconds
            ? ''
            : (string) $r2_metadata->duration_seconds;

        if (
            is_array($pending) && isset($pending['field'], $pending['value'], $pending['error'])
            && self::R2_FIELD_NAME === $pending['field'] && is_string($pending['value'])
        ) {
            $value   = $pending['value'];
            $message = 'duplicate' === $pending['error']
                ? __('That R2 object is already assigned to another video. Not saved.', 'tube-admin')
                : __(
                    'That R2 URL/key could not be validated — check it points at the configured R2 domain and is reachable. Not saved.', // phpcs:ignore Generic.Files.LineLength.TooLong -- a single translatable string literal (WordPress.WP.I18n.NonSingularStringLiteralText forbids splitting it via concatenation).
                    'tube-admin'
                );
            ?>
            <div class="notice notice-error inline" style="margin: 0 0 8px;">
                <p><?php echo esc_html($message); ?></p>
            </div>
            <?php
        }
        ?>
        <p>
            <label for="<?php echo esc_attr(self::R2_FIELD_NAME); ?>">
                <?php esc_html_e('R2 URL or object key', 'tube-admin'); ?>
            </label>
            <input
                type="text"
                id="<?php echo esc_attr(self::R2_FIELD_NAME); ?>"
                name="<?php echo esc_attr(self::R2_FIELD_NAME); ?>"
                class="widefat"
                value="<?php echo esc_attr($value); ?>"
                placeholder="https://media.example.com/path/video.mp4 or path/video.mp4"
            />
        </p>
        <p class="description">
            <?php esc_html_e('A full R2 media URL or a bare object key/path. Must be unique.', 'tube-admin'); ?>
        </p>
        <p>
            <label for="<?php echo esc_attr(self::R2_DURATION_FIELD_NAME); ?>">
                <?php esc_html_e('Duration (seconds)', 'tube-admin'); ?>
            </label>
            <input
                type="number"
                min="0"
                step="1"
                id="<?php echo esc_attr(self::R2_DURATION_FIELD_NAME); ?>"
                name="<?php echo esc_attr(self::R2_DURATION_FIELD_NAME); ?>"
                class="widefat"
                value="<?php echo esc_attr($duration_value); ?>"
            />
        </p>
        <p class="description">
            <?php
            esc_html_e(
                'Optional, but shown anywhere a duration normally is (cards, watch page, search) once set. R2 has no automatic way to read this without downloading the file.', // phpcs:ignore Generic.Files.LineLength.TooLong -- a single translatable string literal (WordPress.WP.I18n.NonSingularStringLiteralText forbids splitting it via concatenation).
                'tube-admin'
            );
            ?>
        </p>
        <?php
        if (null !== $r2_metadata) :
            ?>
            <p class="description">
                <?php
                echo esc_html(
                    sprintf(
                    /* translators: %s: readiness status. */
                        __('Readiness: %s', 'tube-admin'),
                        CfStreamStatus::Ready === $r2_metadata->cf_status
                            ? __('ready', 'tube-admin')
                            : __('unreachable/invalid — re-save to retry', 'tube-admin')
                    )
                );
                ?>
            </p>
            <?php
        endif;
    }

    /**
     * `admin-post.php` handler: manually re-run a live Cloudflare Stream
     * lookup for one video's already-saved UID, then redirect back to
     * this same Edit Video screen with a result notice.
     *
     * The one-video counterpart to `wp tube-core stream:resync` — see
     * `Tube_Core\CLI\StreamCommand`'s own docblock for why both exist and
     * when each is the right tool (this one for "resync this specific
     * video I'm already looking at right now"; the CLI command for
     * backfilling/re-checking many videos at once). Stream-only: R2 has
     * no encoding pipeline to poll, so there's nothing this action would
     * do differently for it — the meta box simply doesn't render this
     * link for an R2 video (see {@see self::render_stream_fields()}).
     */
    public static function handle_resync(): void
    {
        check_admin_referer(self::RESYNC_NONCE_ACTION);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce covering this entire request was already verified by check_admin_referer() immediately above.
        $video_id = absint(wp_unslash(Request::string($_GET, 'video_id')));

        if (0 === $video_id || ! current_user_can('edit_post', $video_id)) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'tube-admin'));
        }

        $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id);
        $resynced = null !== $metadata
            && VideoSource::CloudflareStream === $metadata->source
            && null !== $metadata->cf_stream_uid
            && Tube_Core_Plugin::instance()->stream_metadata_syncer()->sync($metadata->cf_stream_uid);

        wp_safe_redirect(
            add_query_arg(
                [
                    'post'                => $video_id,
                    'action'              => 'edit',
                    'tube_admin_resynced' => $resynced ? '1' : '0',
                ],
                admin_url('post.php')
            )
        );

        exit;
    }

    /**
     * `admin_notices` handler: report the result of a manual resync
     * (self::handle_resync()'s own redirect target), if this page load is
     * that redirect.
     */
    public static function render_resync_notice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect result, not a state-changing action.
        if (! isset($_GET['tube_admin_resynced'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect result.
        $resynced = '1' === Request::string($_GET, 'tube_admin_resynced');

        if ($resynced) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Resynced from Cloudflare Stream.', 'tube-admin'); ?></p>
            </div>
            <?php

            return;
        }
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <?php
                esc_html_e(
                    "Could not resync from Cloudflare Stream — it may be unreachable, credentials aren't configured, or this UID isn't recognized on the configured account. Existing data was left unchanged.", // phpcs:ignore Generic.Files.LineLength.TooLong -- a single translatable string literal (WordPress.WP.I18n.NonSingularStringLiteralText forbids splitting it via concatenation).
                    'tube-admin'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Persist the submitted video source. Called on `save_post_video`.
     * Dispatches to {@see self::save_stream()} or {@see self::save_r2()}
     * based on the submitted source choice — only ever one of the two
     * runs for a given save.
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

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified above.
        $submitted_source = sanitize_key(wp_unslash(Request::string($_POST, self::SOURCE_FIELD_NAME)));
        $source           = VideoSource::tryFrom($submitted_source) ?? VideoSource::CloudflareStream;

        if (VideoSource::R2Mp4 === $source) {
            self::save_r2($post_id);

            return;
        }

        self::save_stream($post_id);
    }

    /**
     * Persist a submitted Cloudflare Stream UID — unchanged from before R2 support existed.
     *
     * Validation posture: an empty submitted value is a silent no-op (a
     * video mid-draft, with no Stream data yet, is a normal, expected
     * state — this is not a required-on-every-save field). A **duplicate**
     * UID (already owned by a different video) is rejected: the write is
     * skipped, and the submitted value + an error notice are replayed on
     * the next render via a short-lived transient (see
     * {@see self::pending_key()}) so the administrator sees exactly what
     * they typed and why it wasn't saved — without touching anything
     * else. This hook runs on `save_post_video`, which WordPress core
     * fires *after* the post row itself (title, excerpt, status, etc.) is
     * already written, so a rejected UID never rolls back or blocks any
     * of the post's other fields; only this one field's write is skipped.
     *
     * **Duration/status sync**: a manually-entered UID references a video
     * that already exists on the Cloudflare account but was never routed
     * through this project's own import pipeline, so it will never
     * trigger `Tube_Core\Stream\WebhookController`'s push-based webhook —
     * without this, such a video would stay at its `create()`-time
     * default (`Pending`, no duration) forever. Whenever the UID is newly
     * set or changed, `Tube_Core\Stream\StreamMetadataSyncer` (a live pull
     * against Cloudflare's own API) is called to fetch and apply the real
     * status/duration immediately.
     *
     * @param int $post_id The video post ID.
     */
    private static function save_stream(int $post_id): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by self::save().
        $cf_stream_uid = sanitize_text_field(wp_unslash(Request::string($_POST, self::FIELD_NAME)));

        if ('' === $cf_stream_uid) {
            return;
        }

        $metadata_repository = Tube_Core_Plugin::instance()->video_metadata_repository();
        $existing_owner_id   = $metadata_repository->find_video_id_by_stream_uid($cf_stream_uid);

        if (null !== $existing_owner_id && $existing_owner_id !== $post_id) {
            set_transient(
                self::pending_key($post_id),
                [
                    'field' => self::FIELD_NAME,
                    'value' => $cf_stream_uid,
                    'error' => 'duplicate',
                ],
                self::PENDING_TTL_SECONDS
            );

            return;
        }

        $current               = $metadata_repository->find($post_id);
        $uid_is_new_or_changed = null === $current
            || VideoSource::CloudflareStream !== $current->source
            || $cf_stream_uid !== $current->cf_stream_uid;

        if (null === $current || VideoSource::CloudflareStream !== $current->source) {
            $metadata_repository->create($post_id, $cf_stream_uid, CfStreamStatus::Pending);
        } elseif ($cf_stream_uid !== $current->cf_stream_uid) {
            $metadata_repository->update_stream_uid($post_id, $cf_stream_uid);
        }

        if ($uid_is_new_or_changed) {
            // Only sync when the UID itself is new/changed — not on every
            // unrelated post save (save_post_video fires for those too),
            // per StreamMetadataSyncer's own "never corrupt on failure"
            // contract: a failed lookup here (unconfigured credentials,
            // network error, unrecognized UID) leaves whatever
            // duration/status this video already had untouched, exactly
            // as if this call had never happened.
            Tube_Core_Plugin::instance()->stream_metadata_syncer()->sync($cf_stream_uid);
        }
    }

    /**
     * Persist a submitted R2/direct-MP4 source — see class docblock for
     * the full validation posture.
     *
     * @param int $post_id The video post ID.
     */
    private static function save_r2(int $post_id): void
    {
        // Deliberately NOT sanitize_text_field(): it strips any "%XX"-shaped
        // sequence on the assumption it's an encoded control character
        // sneaking through free text -- exactly the byte pattern a real R2
        // URL/key legitimately contains (percent-encoded spaces/Unicode),
        // so running it through that function here would silently mangle
        // every non-trivial filename before R2MediaUrlNormalizer ever sees
        // it (confirmed live: it turned this feature's own real example
        // into a 404). wp_unslash() (undoing WordPress's magic-quotes-style
        // POST slashing) is still necessary; trim() bounds the value to a
        // single line. R2MediaUrlNormalizer::normalize() is the real
        // validator here — it already rejects control characters, path
        // traversal, and anything not matching the configured host.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by self::save().
        $raw_input = trim(wp_unslash(Request::string($_POST, self::R2_FIELD_NAME)));

        if ('' === $raw_input) {
            return;
        }

        $tube_core  = Tube_Core_Plugin::instance();
        $normalizer = $tube_core->r2_media_url_normalizer();
        $object_key = $normalizer->normalize($raw_input);

        if (null === $object_key) {
            set_transient(
                self::pending_key($post_id),
                [
                    'field' => self::R2_FIELD_NAME,
                    'value' => $raw_input,
                    'error' => 'invalid',
                ],
                self::PENDING_TTL_SECONDS
            );

            return;
        }

        $metadata_repository = $tube_core->video_metadata_repository();
        $existing_owner_id   = $metadata_repository->find_video_id_by_r2_object_key($object_key);

        if (null !== $existing_owner_id && $existing_owner_id !== $post_id) {
            set_transient(
                self::pending_key($post_id),
                [
                    'field' => self::R2_FIELD_NAME,
                    'value' => $raw_input,
                    'error' => 'duplicate',
                ],
                self::PENDING_TTL_SECONDS
            );

            return;
        }

        $current = $metadata_repository->find($post_id);

        if (null === $current || VideoSource::R2Mp4 !== $current->source) {
            $metadata_repository->create_r2($post_id, $object_key, CfStreamStatus::Pending);
        } elseif ($object_key !== $current->r2_object_key) {
            $metadata_repository->update_r2_object_key($post_id, $object_key);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by self::save().
        $duration_raw     = sanitize_text_field(wp_unslash(Request::string($_POST, self::R2_DURATION_FIELD_NAME)));
        $duration_seconds = is_numeric($duration_raw) && (int) $duration_raw >= 0 ? (int) $duration_raw : null;

        // Always a live check, even if the object key itself didn't
        // change on this save — re-saving is also how an admin retries a
        // video that was transiently unreachable the first time (the
        // meta box's own "Readiness: unreachable/invalid — re-save to
        // retry" message points back at exactly this).
        $is_reachable = $tube_core->r2_video_validator()->is_reachable_video($normalizer->public_url($object_key));

        $tube_core->status_updater()->handle_for_video(
            $post_id,
            $is_reachable ? CfStreamStatus::Ready : CfStreamStatus::Error,
            $duration_seconds
        );
    }

    /**
     * The transient key holding a rejected-duplicate submission's
     * attempted value for one video, scoped per-video so two editors
     * working on different videos never collide.
     *
     * @param int $post_id The video post ID.
     */
    private static function pending_key(int $post_id): string
    {
        return 'tube_admin_stream_uid_pending_' . $post_id;
    }
}
