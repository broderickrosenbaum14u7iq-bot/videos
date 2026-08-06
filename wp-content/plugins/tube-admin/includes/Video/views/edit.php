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

    <?php if (null === $metadata) : ?>
        <div class="notice notice-warning">
            <p><?php esc_html_e('This video has no Cloudflare Stream metadata yet.', 'tube-admin'); ?></p>
        </div>
    <?php else : ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Cloudflare Stream UID', 'tube-admin'); ?></th>
                <td><code><?php echo esc_html($metadata->cf_stream_uid); ?></code></td>
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
        </table>
    <?php endif; ?>

    <form
        method="post"
        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
        enctype="multipart/form-data"
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
                        <?php esc_html_e('The frame offset the default poster is extracted from.', 'tube-admin'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="tube-admin-poster-image">
                        <?php esc_html_e('Custom Poster Image', 'tube-admin'); ?>
                    </label>
                </th>
                <td>
                    <?php $tube_admin_poster_id = null === $metadata ? null : $metadata->poster_image_id; ?>
                    <?php if (null !== $tube_admin_poster_id) : ?>
                        <?php $tube_admin_poster_id_str = strval($tube_admin_poster_id); ?>
                        <p>
                            <?php
                            printf(
                                /* translators: %s: Cloudflare Images ID. */
                                esc_html__('Current override: Cloudflare Images ID %s', 'tube-admin'),
                                esc_html($tube_admin_poster_id_str)
                            );
                            ?>
                        </p>
                        <label>
                            <input type="checkbox" name="remove_poster_image" value="1" />
                            <?php esc_html_e('Remove and revert to the default Stream thumbnail', 'tube-admin'); ?>
                        </label>
                        <br />
                    <?php endif; ?>
                    <input type="file" id="tube-admin-poster-image" name="poster_image" accept="image/*" />
                    <p class="description">
                        <?php esc_html_e('Uploads to Cloudflare Images.', 'tube-admin'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="tube-admin-og-image">
                        <?php esc_html_e('Custom OG Image', 'tube-admin'); ?>
                    </label>
                </th>
                <td>
                    <?php $tube_admin_og_id = null === $metadata ? null : $metadata->og_image_id; ?>
                    <?php if (null !== $tube_admin_og_id) : ?>
                        <?php $tube_admin_og_id_str = strval($tube_admin_og_id); ?>
                        <p>
                            <?php
                            printf(
                                /* translators: %s: Cloudflare Images ID. */
                                esc_html__('Current override: Cloudflare Images ID %s', 'tube-admin'),
                                esc_html($tube_admin_og_id_str)
                            );
                            ?>
                        </p>
                        <label>
                            <input type="checkbox" name="remove_og_image" value="1" />
                            <?php esc_html_e('Remove and revert to the default OG image', 'tube-admin'); ?>
                        </label>
                        <br />
                    <?php endif; ?>
                    <input type="file" id="tube-admin-og-image" name="og_image" accept="image/*" />
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
