<?php
/**
 * The Cloudflare Stream UID meta box on the native `video` edit screen.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Video;

use Tube_Admin\Support\Request;
use Tube_Core\Content\VideoPostType;
use Tube_Core\Plugin as Tube_Core_Plugin;
use Tube_Core\Video\CfStreamStatus;
use WP_Post;

/**
 * Exposes and persists `wp_tube_video_metadata.cf_stream_uid` directly on
 * WordPress's own native `Videos → Add New`/`Edit Video` screen (`post-new.php`/
 * `post.php` for the `video` post type), via a standard `add_meta_box()` +
 * `save_post_video` pair — the same canonical storage
 * `Tube_Core\Video\Repositories\VideoMetadataRepository` already owns
 * (`create()`/`update_stream_uid()`/`find_video_id_by_stream_uid()`), not a
 * second/parallel metadata system. `Tube_Admin\Video\VideoDetailsScreen`
 * previously required a *second* trip to a separate `wp-admin` screen just
 * to set this one field; that requirement is removed by putting the field
 * where an editor already is when creating/editing a video.
 *
 * Validation posture: an empty submitted value is a silent no-op (a video
 * mid-draft, with no Stream data yet, is a normal, expected state — this
 * is not a required-on-every-save field, since `save_post_video` also
 * fires for ordinary autosaves/draft saves that have nothing to do with
 * Stream). A **duplicate** UID (already owned by a different video) is
 * rejected: the write is skipped, and the submitted value + an error
 * notice are replayed on the next render via a short-lived transient
 * (see {@see self::pending_key()}) so the administrator sees exactly what
 * they typed and why it wasn't saved — without touching anything else.
 * This hook runs on `save_post_video`, which WordPress core fires *after*
 * the post row itself (title, excerpt, status, etc.) is already written,
 * so a rejected UID never rolls back or blocks any of the post's other
 * fields; only this one field's write is skipped.
 *
 * **Duration/status sync**: a manually-entered UID references a video
 * that already exists on the Cloudflare account but was never routed
 * through this project's own import pipeline, so it will never trigger
 * `Tube_Core\Stream\WebhookController`'s push-based webhook — without
 * this, such a video would stay at its `create()`-time default
 * (`Pending`, no duration) forever. Whenever the UID is newly set or
 * changed, `Tube_Core\Stream\StreamMetadataSyncer` (a live pull against
 * Cloudflare's own API) is called to fetch and apply the real
 * status/duration immediately — the same `update_status()` write path
 * the webhook already uses, not a second one. A failed lookup (no
 * credentials configured, network error, unrecognized UID) leaves
 * whatever the video already had untouched; see that class's own
 * docblock.
 */
final class StreamUidMetaBox
{
    /**
     * Nonce action covering this meta box's own field.
     */
    private const NONCE_ACTION = 'tube_admin_video_stream_uid';

    /**
     * The nonce field's `$_POST` name.
     */
    private const NONCE_NAME = 'tube_admin_video_stream_uid_nonce';

    /**
     * The Stream UID input's `$_POST` name.
     */
    private const FIELD_NAME = 'tube_admin_cf_stream_uid';

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
            __('Cloudflare Stream', 'tube-admin'),
            [self::class, 'render'],
            VideoPostType::POST_TYPE,
            'side',
            'high'
        );
    }

    /**
     * Render the meta box: a nonce field, an optional rejected-duplicate
     * notice, and the Stream UID input itself.
     *
     * @param WP_Post $post The video post currently being edited.
     */
    public static function render(WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($post->ID);
        $value    = null === $metadata ? '' : $metadata->cf_stream_uid;

        $pending = get_transient(self::pending_key($post->ID));

        if (is_array($pending) && isset($pending['value'], $pending['error']) && is_string($pending['value'])) {
            $value = $pending['value'];
            delete_transient(self::pending_key($post->ID));
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
        if (null !== $metadata) :
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
     * `admin-post.php` handler: manually re-run a live Cloudflare Stream
     * lookup for one video's already-saved UID, then redirect back to
     * this same Edit Video screen with a result notice.
     *
     * The one-video counterpart to `wp tube-core stream:resync` — see
     * `Tube_Core\CLI\StreamCommand`'s own docblock for why both exist and
     * when each is the right tool (this one for "resync this specific
     * video I'm already looking at right now"; the CLI command for
     * backfilling/re-checking many videos at once).
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
     * Persist the submitted Stream UID. Called on `save_post_video`.
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
        $cf_stream_uid = sanitize_text_field(wp_unslash(Request::string($_POST, self::FIELD_NAME)));

        if ('' === $cf_stream_uid) {
            // No Stream UID entered yet — a normal, expected state for a
            // video still being drafted; not an error, nothing to persist.
            return;
        }

        $metadata_repository = Tube_Core_Plugin::instance()->video_metadata_repository();
        $existing_owner_id   = $metadata_repository->find_video_id_by_stream_uid($cf_stream_uid);

        if (null !== $existing_owner_id && $existing_owner_id !== $post_id) {
            set_transient(
                self::pending_key($post_id),
                [
                    'value' => $cf_stream_uid,
                    'error' => 'duplicate',
                ],
                self::PENDING_TTL_SECONDS
            );

            return;
        }

        $current               = $metadata_repository->find($post_id);
        $uid_is_new_or_changed = null === $current || $cf_stream_uid !== $current->cf_stream_uid;

        if (null === $current) {
            $metadata_repository->create($post_id, $cf_stream_uid, CfStreamStatus::Pending);
        } elseif ($cf_stream_uid !== $current->cf_stream_uid) {
            $metadata_repository->update_stream_uid($post_id, $cf_stream_uid);
        }

        if ($uid_is_new_or_changed) {
            // Only sync when the UID itself is new/changed — not on every
            // unrelated post save (save_post_video fires for those too),
            // per StreamMetadataSyncer's own "never corrupt on failure"
            // contract: a failed lookup here (unconfigured credentials,
            // network error, unrecognized UID) leaves whatever duration/
            // status this video already had untouched, exactly as if
            // this call had never happened.
            Tube_Core_Plugin::instance()->stream_metadata_syncer()->sync($cf_stream_uid);
        }
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
