<?php
/**
 * View for BulkToolsScreen::render().
 *
 * Included with $search, $videos, $all_actors, $all_studios already in
 * scope — see BulkToolsScreen::render(). Every local variable this file
 * itself defines is `tube_admin_`-prefixed, per `tube-theme`'s own
 * PrefixAllGlobals convention for top-level template files.
 *
 * @package Tube_Admin
 *
 * @var string $search
 * @var list<array{id: int, title: string}> $videos
 * @var \Tube_Core\Content\Actor[] $all_actors
 * @var \Tube_Core\Content\Studio[] $all_studios
 */

declare(strict_types=1);

use Tube_Admin\Bulk\BulkToolsScreen;

?>
<div class="wrap">
    <h1><?php esc_html_e('Bulk Tools', 'tube-admin'); ?></h1>

    <?php
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect result, not a state-changing action.
    if (isset($_GET['result'])) :
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        $tube_admin_actors_affected = absint(wp_unslash(\Tube_Admin\Support\Request::string($_GET, 'actors')));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        $tube_admin_studios_affected = absint(wp_unslash(\Tube_Admin\Support\Request::string($_GET, 'studios')));
        $tube_admin_actors_str       = strval($tube_admin_actors_affected);
        $tube_admin_studios_str      = strval($tube_admin_studios_affected);
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                printf(
                    /* translators: 1: number of actor rows changed, 2: number of studio rows changed */
                    esc_html__('Updated %1$s actor assignment(s) and %2$s studio assignment(s).', 'tube-admin'),
                    esc_html($tube_admin_actors_str),
                    esc_html($tube_admin_studios_str)
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
        <input type="hidden" name="page" value="<?php echo esc_attr(BulkToolsScreen::SLUG); ?>" />
        <label class="screen-reader-text" for="tube-admin-bulk-search">
            <?php esc_html_e('Search videos by title', 'tube-admin'); ?>
        </label>
        <input
            type="search"
            id="tube-admin-bulk-search"
            name="s"
            value="<?php echo esc_attr($search); ?>"
            placeholder="<?php esc_attr_e('Search videos by title&hellip;', 'tube-admin'); ?>"
        />
        <?php submit_button(__('Search', 'tube-admin'), 'secondary', 'submit', false); ?>
    </form>

    <?php if ('' !== $search) : ?>
        <form
            method="post"
            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            style="margin-top:1em;"
        >
            <input type="hidden" name="action" value="tube_admin_bulk_assign" />
            <?php wp_nonce_field('tube_admin_bulk_assign'); ?>

            <h2><?php esc_html_e('1. Select Videos', 'tube-admin'); ?></h2>
            <?php if ([] === $videos) : ?>
                <p><?php esc_html_e('No matching videos.', 'tube-admin'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col" style="width:2em;">
                                <span class="screen-reader-text">
                                    <?php esc_html_e('Select', 'tube-admin'); ?>
                                </span>
                            </th>
                            <th scope="col"><?php esc_html_e('Title', 'tube-admin'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($videos as $tube_admin_video) : ?>
                            <?php $tube_admin_video_id_str = strval($tube_admin_video['id']); ?>
                            <tr>
                                <td>
                                    <label>
                                        <span class="screen-reader-text">
                                            <?php echo esc_html($tube_admin_video['title']); ?>
                                        </span>
                                        <input
                                            type="checkbox"
                                            name="video_ids[]"
                                            value="<?php echo esc_attr($tube_admin_video_id_str); ?>"
                                        />
                                    </label>
                                </td>
                                <td><?php echo esc_html($tube_admin_video['title']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2><?php esc_html_e('2. Select Actors/Studios', 'tube-admin'); ?></h2>
            <div style="display:flex;gap:3em;">
                <fieldset>
                    <legend><strong><?php esc_html_e('Actors', 'tube-admin'); ?></strong></legend>
                    <?php if ([] === $all_actors) : ?>
                        <p><?php esc_html_e('No actors exist yet.', 'tube-admin'); ?></p>
                    <?php endif; ?>
                    <?php foreach ($all_actors as $tube_admin_actor) : ?>
                        <?php $tube_admin_actor_id_str = strval($tube_admin_actor->id); ?>
                        <label style="display:block;">
                            <input
                                type="checkbox"
                                name="actor_ids[]"
                                value="<?php echo esc_attr($tube_admin_actor_id_str); ?>"
                            />
                            <?php echo esc_html($tube_admin_actor->name); ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <fieldset>
                    <legend><strong><?php esc_html_e('Studios', 'tube-admin'); ?></strong></legend>
                    <?php if ([] === $all_studios) : ?>
                        <p><?php esc_html_e('No studios exist yet.', 'tube-admin'); ?></p>
                    <?php endif; ?>
                    <?php foreach ($all_studios as $tube_admin_studio) : ?>
                        <?php $tube_admin_studio_id_str = strval($tube_admin_studio->id); ?>
                        <label style="display:block;">
                            <input
                                type="checkbox"
                                name="studio_ids[]"
                                value="<?php echo esc_attr($tube_admin_studio_id_str); ?>"
                            />
                            <?php echo esc_html($tube_admin_studio->name); ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            </div>

            <h2><?php esc_html_e('3. Choose Action', 'tube-admin'); ?></h2>
            <p>
                <label>
                    <input type="radio" name="bulk_mode" value="add" checked="checked" />
                    <?php esc_html_e('Add to selected videos', 'tube-admin'); ?>
                </label>
                <br />
                <label>
                    <input type="radio" name="bulk_mode" value="remove" />
                    <?php esc_html_e('Remove from selected videos', 'tube-admin'); ?>
                </label>
            </p>

            <?php submit_button(__('Apply', 'tube-admin')); ?>
        </form>
    <?php endif; ?>
</div>
