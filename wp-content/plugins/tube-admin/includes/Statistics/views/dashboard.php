<?php
/**
 * View for StatisticsDashboardScreen::render().
 *
 * Included with $rows, $order_by, $paged, $total already in scope — see
 * StatisticsDashboardScreen::render(). Every local variable this file
 * itself defines is `tube_admin_`-prefixed, per `tube-theme`'s own
 * PrefixAllGlobals convention for top-level template files.
 *
 * @package Tube_Admin
 *
 * @var list<array{video_id: int, views_total: int, views_today: int, views_7d: int, views_30d: int}> $rows
 * @var string $order_by
 * @var int $paged
 * @var int $total
 */

declare(strict_types=1);

$tube_admin_columns = [
    'views_total' => __('Total Views', 'tube-admin'),
    'views_today' => __('Today', 'tube-admin'),
    'views_7d'    => __('7 Days', 'tube-admin'),
    'views_30d'   => __('30 Days', 'tube-admin'),
];

$tube_admin_total_pages = (int) ceil($total / 20);
?>
<div class="wrap">
    <h1><?php esc_html_e('Video Statistics', 'tube-admin'); ?></h1>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Video', 'tube-admin'); ?></th>
                <?php foreach ($tube_admin_columns as $tube_admin_column_key => $tube_admin_column_label) : ?>
                    <?php
                    $tube_admin_sort_url   = add_query_arg(
                        [
                            'orderby' => $tube_admin_column_key,
                            'paged'   => 1,
                        ]
                    );
                    $tube_admin_is_current = $order_by === $tube_admin_column_key;
                    ?>
                    <th scope="col">
                        <a href="<?php echo esc_url($tube_admin_sort_url); ?>">
                            <?php echo esc_html($tube_admin_column_label); ?>
                            <?php if ($tube_admin_is_current) : ?>
                                <span aria-hidden="true">&darr;</span>
                                <span class="screen-reader-text">
                                    <?php esc_html_e('(currently sorted by this column)', 'tube-admin'); ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if ([] === $rows) : ?>
                <tr>
                    <td colspan="5"><?php esc_html_e('No statistics recorded yet.', 'tube-admin'); ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($rows as $tube_admin_row) : ?>
                <?php
                $tube_admin_title = get_the_title($tube_admin_row['video_id']);
                $tube_admin_title = '' === $tube_admin_title
                    ? sprintf(
                        /* translators: %d: video post ID. */
                        __('Video #%d', 'tube-admin'),
                        $tube_admin_row['video_id']
                    )
                    : $tube_admin_title;
                $tube_admin_edit_link = get_edit_post_link($tube_admin_row['video_id']);
                $tube_admin_edit_link = null === $tube_admin_edit_link ? '' : $tube_admin_edit_link;

                $tube_admin_total_str = strval($tube_admin_row['views_total']);
                $tube_admin_today_str = strval($tube_admin_row['views_today']);
                $tube_admin_d7_str    = strval($tube_admin_row['views_7d']);
                $tube_admin_d30_str   = strval($tube_admin_row['views_30d']);
                ?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url($tube_admin_edit_link); ?>">
                            <?php echo esc_html($tube_admin_title); ?>
                        </a>
                    </td>
                    <td><?php echo esc_html($tube_admin_total_str); ?></td>
                    <td><?php echo esc_html($tube_admin_today_str); ?></td>
                    <td><?php echo esc_html($tube_admin_d7_str); ?></td>
                    <td><?php echo esc_html($tube_admin_d30_str); ?></td>
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
