<?php
/**
 * View for SettingsScreen::render().
 *
 * Included with $rows already in scope — see SettingsScreen::render().
 * Every local variable this file itself defines is `tube_admin_`-
 * prefixed, per `tube-theme`'s own PrefixAllGlobals convention for
 * top-level template files.
 *
 * @package Tube_Admin
 *
 * @var list<array{label: string, set: bool}> $rows
 */

declare(strict_types=1);

?>
<div class="wrap">
    <h1><?php esc_html_e('Settings', 'tube-admin'); ?></h1>

    <div class="notice notice-info">
        <p>
            <?php esc_html_e('Configured via docker-compose.yml / .env, not here.', 'tube-admin'); ?>
            <br />
            <?php esc_html_e('Edit and restart the stack to change a value.', 'tube-admin'); ?>
        </p>
    </div>

    <table class="wp-list-table widefat fixed striped" style="max-width:700px;">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Setting', 'tube-admin'); ?></th>
                <th scope="col"><?php esc_html_e('Status', 'tube-admin'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $tube_admin_row) : ?>
                <tr>
                    <td><?php echo esc_html($tube_admin_row['label']); ?></td>
                    <td>
                        <?php if ($tube_admin_row['set']) : ?>
                            <span style="color:#00a32a;">&#9679;</span>
                            <?php esc_html_e('Set', 'tube-admin'); ?>
                        <?php else : ?>
                            <span style="color:#d63638;">&#9679;</span>
                            <?php esc_html_e('Not set', 'tube-admin'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
