<?php
/**
 * View for VideoDetailsScreen::render() — the single-video edit form.
 *
 * Included with $video, $video_id, $metadata, $all_actors, $all_studios,
 * $assigned_actors, $assigned_studios already in scope — see
 * VideoDetailsScreen::render(). Every local variable this file itself
 * defines is `tube_admin_`-prefixed, per `tube-theme`'s own
 * PrefixAllGlobals convention for top-level template files.
 *
 * @package Tube_Admin
 *
 * @var \WP_Post $video
 * @var int $video_id
 * @var \Tube_Core\Video\VideoMetadata|null $metadata
 * @var \Tube_Core\Content\Actor[] $all_actors
 * @var \Tube_Core\Content\Studio[] $all_studios
 * @var int[] $assigned_actors
 * @var int[] $assigned_studios
 */

declare(strict_types=1);

use Tube_Admin\Video\VideoDetailsScreen;

$tube_admin_back_url = remove_query_arg(['video_id', 'saved']);
?>
<div class="wrap">
    <h1><?php echo esc_html(get_the_title($video)); ?></h1>

    <p>
        <a href="<?php echo esc_url($tube_admin_back_url); ?>">
            <?php esc_html_e('&laquo; Back to search', 'tube-admin'); ?>
        </a>
        &nbsp;|&nbsp;
        <?php $tube_admin_post_edit_url = get_edit_post_link($video_id); ?>
        <?php if (null !== $tube_admin_post_edit_url) : ?>
            <a href="<?php echo esc_url($tube_admin_post_edit_url); ?>">
                <?php esc_html_e('Edit title/excerpt in the post editor', 'tube-admin'); ?>
            </a>
        <?php endif; ?>
    </p>

    <?php
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect result, not a state-changing action.
    if (isset($_GET['saved'])) :
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Video details saved.', 'tube-admin'); ?></p>
        </div>
    <?php endif; ?>

    <?php
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect result.
    $tube_admin_error = sanitize_key(wp_unslash(\Tube_Admin\Support\Request::string($_GET, 'error')));
    ?>
    <?php if ('no_stream_uid_yet' === $tube_admin_error) : ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <?php esc_html_e('This video has no Cloudflare Stream UID yet, so nothing was saved.', 'tube-admin'); ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if (null === $metadata) : ?>
        <div class="notice notice-warning">
            <p>
                <?php
                printf(
                    /* translators: %s: link to the video's own Edit Video screen. */
                    esc_html__('No Cloudflare Stream UID yet. Set it on its own %s first.', 'tube-admin'),
                    null === $tube_admin_post_edit_url
                        ? esc_html__('Edit Video', 'tube-admin')
                        : '<a href="' . esc_url($tube_admin_post_edit_url) . '">'
                            . esc_html__('Edit Video', 'tube-admin') . '</a>'
                );
                ?>
            </p>
        </div>
    <?php else : ?>
        <table class="form-table" role="presentation">
            <?php $tube_admin_is_r2 = \Tube_Core\Video\VideoSource::R2Mp4 === $metadata->source; ?>
            <tr>
                <th scope="row">
                    <?php
                    echo esc_html(
                        $tube_admin_is_r2
                            ? __('R2 object key', 'tube-admin')
                            : __('Cloudflare Stream UID', 'tube-admin')
                    );
                    ?>
                </th>
                <?php
                $tube_admin_source_value = $tube_admin_is_r2 ? $metadata->r2_object_key : $metadata->cf_stream_uid;
                $tube_admin_source_value = $tube_admin_source_value ?? '';
                ?>
                <td>
                    <code><?php echo esc_html($tube_admin_source_value); ?></code>
                    <?php if (null !== $tube_admin_post_edit_url) : ?>
                        &nbsp;
                        <a href="<?php echo esc_url($tube_admin_post_edit_url); ?>">
                            <?php esc_html_e('Change it on the Edit Video screen', 'tube-admin'); ?>
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Encoding Status', 'tube-admin'); ?></th>
                <td><?php echo esc_html($metadata->cf_status->value); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Duration', 'tube-admin'); ?></th>
                <td>
                    <?php if (null === $metadata->duration_seconds) : ?>
                        <?php esc_html_e('Unknown', 'tube-admin'); ?>
                    <?php else : ?>
                        <?php $tube_admin_duration_str = strval($metadata->duration_seconds); ?>
                        <?php echo esc_html($tube_admin_duration_str); ?>
                        <?php esc_html_e('seconds', 'tube-admin'); ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Poster Image', 'tube-admin'); ?></th>
                <td>
                    <?php
                    $tube_admin_poster_preview_url = null === $metadata->poster_image_id
                        ? false
                        : wp_get_attachment_image_url($metadata->poster_image_id, 'medium');
                    ?>
                    <?php if (is_string($tube_admin_poster_preview_url)) : ?>
                        <img
                            src="<?php echo esc_url($tube_admin_poster_preview_url); ?>"
                            style="max-width:200px;height:auto;display:block;margin-bottom:8px;"
                            alt=""
                        />
                    <?php else : ?>
                        <?php esc_html_e('None set.', 'tube-admin'); ?>
                    <?php endif; ?>
                    <?php if (null !== $tube_admin_post_edit_url) : ?>
                        <a href="<?php echo esc_url($tube_admin_post_edit_url); ?>">
                            <?php esc_html_e('Change it on the Edit Video screen', 'tube-admin'); ?>
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    <?php endif; ?>

    <form
        method="post"
        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
    >
        <input type="hidden" name="action" value="tube_admin_save_video_details" />
        <?php $tube_admin_video_id_str = strval($video_id); ?>
        <input type="hidden" name="video_id" value="<?php echo esc_attr($tube_admin_video_id_str); ?>" />
        <?php wp_nonce_field('tube_admin_save_video_details'); ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="tube-admin-thumbnail-time">
                        <?php esc_html_e('Thumbnail Offset (seconds)', 'tube-admin'); ?>
                    </label>
                </th>
                <td>
                    <?php
                    $tube_admin_thumbnail_time     = null === $metadata ? 0 : $metadata->thumbnail_time_seconds;
                    $tube_admin_thumbnail_time_str = strval($tube_admin_thumbnail_time);
                    ?>
                    <input
                        type="number"
                        min="0"
                        id="tube-admin-thumbnail-time"
                        name="thumbnail_time_seconds"
                        value="<?php echo esc_attr($tube_admin_thumbnail_time_str); ?>"
                    />
                    <p class="description">
                        <?php
                        esc_html_e(
                            'Currently unused: there is no Cloudflare Stream thumbnail extraction anymore (ADR-0001) — retained only in case a future feature needs it.', // phpcs:ignore Generic.Files.LineLength.TooLong -- a single translatable string literal (WordPress.WP.I18n.NonSingularStringLiteralText forbids splitting it via concatenation).
                            'tube-admin'
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('OG (Share) Image', 'tube-admin'); ?></th>
                <td>
                    <?php
                    $tube_admin_field_name  = 'og_image_id';
                    $tube_admin_field_value = null === $metadata ? null : $metadata->og_image_id;
                    require __DIR__ . '/media-picker.php';
                    ?>
                    <p class="description">
                        <?php
                        esc_html_e(
                            'Used for social-share previews (og:image). Leave empty to omit it — there is no automatic fallback to the poster image.', // phpcs:ignore Generic.Files.LineLength.TooLong -- a single translatable string literal (WordPress.WP.I18n.NonSingularStringLiteralText forbids splitting it via concatenation).
                            'tube-admin'
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Actors', 'tube-admin'); ?></th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text">
                            <?php esc_html_e('Actors assigned to this video', 'tube-admin'); ?>
                        </legend>
                        <?php if ([] === $all_actors) : ?>
                            <p><?php esc_html_e('No actors exist yet — add one below.', 'tube-admin'); ?></p>
                        <?php endif; ?>
                        <?php foreach ($all_actors as $tube_admin_actor) : ?>
                            <?php $tube_admin_actor_id_str = strval($tube_admin_actor->id); ?>
                            <label style="display:block;">
                                <input
                                    type="checkbox"
                                    name="actor_ids[]"
                                    value="<?php echo esc_attr($tube_admin_actor_id_str); ?>"
                                    <?php checked(in_array($tube_admin_actor->id, $assigned_actors, true)); ?>
                                />
                                <?php echo esc_html($tube_admin_actor->name); ?>
                            </label>
                        <?php endforeach; ?>
                        <p>
                            <label for="tube-admin-new-actor">
                                <?php esc_html_e('Add a new actor:', 'tube-admin'); ?>
                            </label>
                            <input type="text" id="tube-admin-new-actor" name="new_actor_name" />
                        </p>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Studios', 'tube-admin'); ?></th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text">
                            <?php esc_html_e('Studios assigned to this video', 'tube-admin'); ?>
                        </legend>
                        <?php if ([] === $all_studios) : ?>
                            <p><?php esc_html_e('No studios exist yet — add one below.', 'tube-admin'); ?></p>
                        <?php endif; ?>
                        <?php foreach ($all_studios as $tube_admin_studio) : ?>
                            <?php $tube_admin_studio_id_str = strval($tube_admin_studio->id); ?>
                            <label style="display:block;">
                                <input
                                    type="checkbox"
                                    name="studio_ids[]"
                                    value="<?php echo esc_attr($tube_admin_studio_id_str); ?>"
                                    <?php checked(in_array($tube_admin_studio->id, $assigned_studios, true)); ?>
                                />
                                <?php echo esc_html($tube_admin_studio->name); ?>
                            </label>
                        <?php endforeach; ?>
                        <p>
                            <label for="tube-admin-new-studio">
                                <?php esc_html_e('Add a new studio:', 'tube-admin'); ?>
                            </label>
                            <input type="text" id="tube-admin-new-studio" name="new_studio_name" />
                        </p>
                    </fieldset>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Save Video Details', 'tube-admin')); ?>
    </form>
</div>
