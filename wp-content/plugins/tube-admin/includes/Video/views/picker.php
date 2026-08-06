<?php
/**
 * View for VideoDetailsScreen::render() — the video picker.
 *
 * Included with $search, $videos already in scope — see
 * VideoDetailsScreen::render(). Every local variable this file itself
 * defines is `tube_admin_`-prefixed, per `tube-theme`'s own
 * PrefixAllGlobals convention for top-level template files.
 *
 * @package Tube_Admin
 *
 * @var string $search
 * @var list<array{id: int, title: string}> $videos
 */

declare(strict_types=1);

?>
<div class="wrap">
    <h1><?php esc_html_e('Video Details', 'tube-admin'); ?></h1>

    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
        <input type="hidden" name="page" value="<?php echo esc_attr(\Tube_Admin\Video\VideoDetailsScreen::SLUG); ?>" />
        <label class="screen-reader-text" for="tube-admin-video-search">
            <?php esc_html_e('Search videos by title', 'tube-admin'); ?>
        </label>
        <input
            type="search"
            id="tube-admin-video-search"
            name="s"
            value="<?php echo esc_attr($search); ?>"
            placeholder="<?php esc_attr_e('Search videos by title&hellip;', 'tube-admin'); ?>"
        />
        <?php submit_button(__('Search', 'tube-admin'), 'secondary', 'submit', false); ?>
    </form>

    <?php if ('' !== $search) : ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:1em;">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Title', 'tube-admin'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ([] === $videos) : ?>
                    <tr>
                        <td><?php esc_html_e('No matching videos.', 'tube-admin'); ?></td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($videos as $tube_admin_video) : ?>
                    <?php
                    $tube_admin_edit_url = add_query_arg(
                        [
                            'page'     => \Tube_Admin\Video\VideoDetailsScreen::SLUG,
                            'video_id' => $tube_admin_video['id'],
                        ],
                        admin_url('admin.php')
                    );
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url($tube_admin_edit_url); ?>">
                                <?php echo esc_html($tube_admin_video['title']); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
