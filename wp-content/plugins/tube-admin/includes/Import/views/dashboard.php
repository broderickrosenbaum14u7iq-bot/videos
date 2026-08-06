<?php
/**
 * View for ImportDashboardScreen::render().
 *
 * Included with $counts, $status_filter, $paged, $items, $total already
 * in scope — see ImportDashboardScreen::render(). Every local variable
 * this file itself defines is `tube_admin_`-prefixed, the same
 * PrefixAllGlobals convention `tube-theme`'s own templates already use
 * (a top-level template file has no enclosing function scope, so PHPCS
 * treats every variable in it as global).
 *
 * @package Tube_Admin
 *
 * @var array<string, int> $counts
 * @var \Tube_Core\Import\ImportStatus|null $status_filter
 * @var int $paged
 * @var list<array{
 *     id: int,
 *     source_key: string,
 *     status: string,
 *     attempts: int,
 *     max_attempts: int,
 *     last_error: string|null,
 *     video_id: int|null,
 *     created_at: string,
 *     updated_at: string
 * }> $items
 * @var int $total
 */

declare(strict_types=1);

use Tube_Admin\Support\Request;
use Tube_Core\Import\ImportStatus;

$tube_admin_total_pages  = (int) ceil($total / 20);
$tube_admin_statuses     = ['pending', 'processing', 'completed', 'failed'];
$tube_admin_status_cases = ImportStatus::cases();
?>
<div class="wrap">
    <h1><?php esc_html_e('Import Queue', 'tube-admin'); ?></h1>

    <?php
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect result, not a state-changing action.
    if (isset($_GET['processed'])) :
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        $tube_admin_processed = absint(wp_unslash(Request::string($_GET, 'processed')));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        $tube_admin_batch_completed = absint(wp_unslash(Request::string($_GET, 'completed')));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        $tube_admin_batch_failed = absint(wp_unslash(Request::string($_GET, 'failed')));

        $tube_admin_processed_str = strval($tube_admin_processed);
        $tube_admin_completed_str = strval($tube_admin_batch_completed);
        $tube_admin_failed_str    = strval($tube_admin_batch_failed);
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <?php
                printf(
                    /* translators: 1: total items processed, 2: number completed, 3: number failed */
                    esc_html__('Processed %1$s item(s): %2$s completed, %3$s failed.', 'tube-admin'),
                    esc_html($tube_admin_processed_str),
                    esc_html($tube_admin_completed_str),
                    esc_html($tube_admin_failed_str)
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <?php
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect result, not a state-changing action.
    if (isset($_GET['requeued'])) :
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        $tube_admin_requeued_ok    = '1' === wp_unslash(Request::string($_GET, 'requeued'));
        $tube_admin_requeue_notice = $tube_admin_requeued_ok ? 'notice-success' : 'notice-error';
        ?>
        <div class="notice <?php echo esc_attr($tube_admin_requeue_notice); ?> is-dismissible">
            <p>
                <?php if ($tube_admin_requeued_ok) : ?>
                    <?php esc_html_e('Item requeued for retry.', 'tube-admin'); ?>
                <?php else : ?>
                    <?php esc_html_e('Could not requeue that item.', 'tube-admin'); ?>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <ul class="tube-admin-stat-tiles" style="display:flex;gap:1em;list-style:none;margin:1em 0;padding:0;">
        <?php foreach ($tube_admin_statuses as $tube_admin_status_value) : ?>
            <?php
            $tube_admin_status_count     = $counts[ $tube_admin_status_value ] ?? 0;
            $tube_admin_status_count_str = strval($tube_admin_status_count);
            ?>
            <li style="border:1px solid #ccd0d4;padding:0.75em 1.25em;background:#fff;">
                <strong style="display:block;font-size:1.5em;">
                    <?php echo esc_html($tube_admin_status_count_str); ?>
                </strong>
                <span><?php echo esc_html(ucfirst($tube_admin_status_value)); ?></span>
            </li>
        <?php endforeach; ?>
    </ul>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:1em;">
        <input type="hidden" name="action" value="tube_admin_process_import_batch" />
        <?php wp_nonce_field('tube_admin_process_import_batch'); ?>
        <?php submit_button(__('Process Next Batch Now', 'tube-admin'), 'primary', 'submit', false); ?>
        <p class="description">
            <?php esc_html_e('Claims and processes up to 20 pending items now.', 'tube-admin'); ?>
        </p>
    </form>

    <ul class="subsubsub">
        <li>
            <?php $tube_admin_all_class = null === $status_filter ? 'class="current"' : ''; ?>
            <a href="<?php echo esc_url(remove_query_arg('status')); ?>" <?php echo esc_attr($tube_admin_all_class); ?>>
                <?php esc_html_e('All', 'tube-admin'); ?>
            </a> |
        </li>
        <?php foreach ($tube_admin_status_cases as $tube_admin_status_index => $tube_admin_status_case) : ?>
            <?php
            $tube_admin_case_class = $status_filter === $tube_admin_status_case ? 'class="current"' : '';
            $tube_admin_separator  = $tube_admin_status_index < count($tube_admin_status_cases) - 1 ? ' |' : '';
            ?>
            <li>
                <a href="<?php echo esc_url(add_query_arg('status', $tube_admin_status_case->value)); ?>"
                    <?php echo esc_attr($tube_admin_case_class); ?>>
                    <?php echo esc_html(ucfirst($tube_admin_status_case->value)); ?>
                </a><?php echo esc_html($tube_admin_separator); ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('ID', 'tube-admin'); ?></th>
                <th scope="col"><?php esc_html_e('Source Key', 'tube-admin'); ?></th>
                <th scope="col"><?php esc_html_e('Status', 'tube-admin'); ?></th>
                <th scope="col"><?php esc_html_e('Attempts', 'tube-admin'); ?></th>
                <th scope="col"><?php esc_html_e('Last Error', 'tube-admin'); ?></th>
                <th scope="col"><?php esc_html_e('Video', 'tube-admin'); ?></th>
                <th scope="col"><?php esc_html_e('Updated', 'tube-admin'); ?></th>
                <th scope="col"><?php esc_html_e('Actions', 'tube-admin'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ([] === $items) : ?>
                <tr>
                    <td colspan="8"><?php esc_html_e('No queue items.', 'tube-admin'); ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($items as $tube_admin_item) : ?>
                <?php
                $tube_admin_item_id      = (string) $tube_admin_item['id'];
                $tube_admin_attempts_str = $tube_admin_item['attempts'] . ' / ' . $tube_admin_item['max_attempts'];
                $tube_admin_video_id     = $tube_admin_item['video_id'];
                ?>
                <tr>
                    <td><?php echo esc_html($tube_admin_item_id); ?></td>
                    <td><?php echo esc_html($tube_admin_item['source_key']); ?></td>
                    <td><?php echo esc_html($tube_admin_item['status']); ?></td>
                    <td><?php echo esc_html($tube_admin_attempts_str); ?></td>
                    <td><?php echo esc_html($tube_admin_item['last_error'] ?? ''); ?></td>
                    <td>
                        <?php if (null !== $tube_admin_video_id) : ?>
                            <?php
                            $tube_admin_edit_link    = get_edit_post_link($tube_admin_video_id);
                            $tube_admin_edit_link    = null === $tube_admin_edit_link ? '' : $tube_admin_edit_link;
                            $tube_admin_video_id_str = strval($tube_admin_video_id);
                            ?>
                            <a href="<?php echo esc_url($tube_admin_edit_link); ?>">
                                #<?php echo esc_html($tube_admin_video_id_str); ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($tube_admin_item['updated_at']); ?></td>
                    <td>
                        <?php if ('failed' === $tube_admin_item['status']) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="tube_admin_retry_import_item" />
                                <input
                                    type="hidden"
                                    name="item_id"
                                    value="<?php echo esc_attr($tube_admin_item_id); ?>"
                                />
                                <?php wp_nonce_field('tube_admin_retry_import_item'); ?>
                                <button type="submit" class="button button-small">
                                    <?php esc_html_e('Retry', 'tube-admin'); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($tube_admin_total_pages > 1) : ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                $tube_admin_pagination = paginate_links(
                    [
                        'base'      => add_query_arg('paged', '%#%'),
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $tube_admin_total_pages,
                        'prev_text' => __('&laquo; Previous', 'tube-admin'),
                        'next_text' => __('Next &raquo;', 'tube-admin'),
                    ]
                );
                // paginate_links() only returns void when total <= 1 (not the
                // case inside this `if ($tube_admin_total_pages > 1)` block)
                // or when 'type' => 'array' (not passed here) -- always a
                // plain string in this call path.
                echo wp_kses_post($tube_admin_pagination);
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>
