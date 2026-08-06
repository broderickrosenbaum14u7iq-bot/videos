<?php
/**
 * View for SystemStatusScreen::render().
 *
 * Included with $redis_status, $migration_status, $wp_cron_disabled
 * already in scope — see SystemStatusScreen::render(). Every local
 * variable this file itself defines is `tube_admin_`-prefixed, per
 * `tube-theme`'s own PrefixAllGlobals convention for top-level template
 * files.
 *
 * @package Tube_Admin
 *
 * @var array{reachable: bool, message: string} $redis_status
 * @var list<array{
 *     plugin_slug: string,
 *     version: string,
 *     description: string,
 *     applied: bool,
 *     applied_at: string|null
 * }> $migration_status
 * @var bool $wp_cron_disabled
 */

declare(strict_types=1);

?>
<div class="wrap">
    <h1><?php esc_html_e('System Status', 'tube-admin'); ?></h1>

    <h2><?php esc_html_e('Connectivity', 'tube-admin'); ?></h2>
    <table class="widefat" style="max-width:600px;">
        <tbody>
            <tr>
                <td><?php esc_html_e('Redis', 'tube-admin'); ?></td>
                <td>
                    <?php if ($redis_status['reachable']) : ?>
                        <span style="color:#00a32a;">&#9679;</span>
                        <?php esc_html_e('Reachable', 'tube-admin'); ?>
                    <?php else : ?>
                        <span style="color:#d63638;">&#9679;</span>
                        <?php esc_html_e('Unreachable', 'tube-admin'); ?>
                    <?php endif; ?>
                    (<?php echo esc_html($redis_status['message']); ?>)
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('WP-Cron', 'tube-admin'); ?></td>
                <td>
                    <?php if ($wp_cron_disabled) : ?>
                        <span style="color:#00a32a;">&#9679;</span>
                        <?php esc_html_e('Disabled (Linux cron is expected to drive scheduled tasks)', 'tube-admin'); ?>
                    <?php else : ?>
                        <span style="color:#d63638;">&#9679;</span>
                        <?php esc_html_e('Enabled — ARCHITECTURE.md section 7 expects this disabled', 'tube-admin'); ?>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>

    <h2><?php esc_html_e('Migration Status', 'tube-admin'); ?></h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Plugin', 'tube-admin'); ?></th>
                <th scope="col"><?php esc_html_e('Version', 'tube-admin'); ?></th>
                <th scope="col"><?php esc_html_e('Description', 'tube-admin'); ?></th>
                <th scope="col"><?php esc_html_e('Applied', 'tube-admin'); ?></th>
                <th scope="col"><?php esc_html_e('Applied At', 'tube-admin'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ([] === $migration_status) : ?>
                <tr>
                    <td colspan="5"><?php esc_html_e('No migrations registered.', 'tube-admin'); ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($migration_status as $tube_admin_row) : ?>
                <tr>
                    <td><?php echo esc_html($tube_admin_row['plugin_slug']); ?></td>
                    <td><?php echo esc_html($tube_admin_row['version']); ?></td>
                    <td><?php echo esc_html($tube_admin_row['description']); ?></td>
                    <td>
                        <?php if ($tube_admin_row['applied']) : ?>
                            <span style="color:#00a32a;">&#10003;</span>
                        <?php else : ?>
                            <span style="color:#d63638;">&#10007; <?php esc_html_e('Pending', 'tube-admin'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($tube_admin_row['applied_at'] ?? ''); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
