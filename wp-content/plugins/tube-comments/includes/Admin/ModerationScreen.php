<?php
/**
 * The wp-admin "Tube Comments" moderation screen.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Admin;

use Tube_Comments\Comments\CommentService;
use Tube_Comments\Comments\Repositories\CommentReportRepository;
use Tube_Comments\Comments\Repositories\CommentRepository;
use Tube_Comments\Support\Params;
use WP_Post;
use WP_User;

/**
 * The wp-admin "Tube Comments" moderation screen, per Phase 22. Gated on the
 * `moderate_comments` capability — the same core capability that already
 * gates native `wp_comments` moderation, granted by default to
 * Administrator and Editor, so no new custom capability is needed.
 *
 * A dedicated top-level menu, not a replacement for the native
 * "Comments" screen — `wp_comments` is untouched (this project's
 * comments live in `wp_tube_comments` entirely, per the storage
 * decision in `Tube_Comments\Plugin`'s own docblock), so both screens
 * can coexist without conflict.
 */
final class ModerationScreen
{
    private const PAGE_SIZE    = 20;
    private const NONCE_ACTION = 'tube_comments_moderate';

    /**
     * Construct around the service that performs the actual status transitions.
     *
     * @param CommentService $service Performs the actual status transitions.
     */
    public function __construct(private readonly CommentService $service)
    {
    }

    /**
     * `admin_menu` callback.
     */
    public function register_menu(): void
    {
        add_menu_page(
            __('Tube Comments', 'tube-comments'),
            __('Tube Comments', 'tube-comments'),
            'moderate_comments',
            'tube-comments',
            [$this, 'render'],
            'dashicons-admin-comments',
            26
        );
    }

    /**
     * `admin_post_tube_comments_moderate` callback: applies one
     * moderation action (from a single row, or a bulk selection) and
     * redirects back to the screen.
     */
    public function handle_action(): void
    {
        if (! current_user_can('moderate_comments')) {
            wp_die(esc_html__('Bạn không có quyền thực hiện thao tác này.', 'tube-comments'), '', ['response' => 403]);
        }

        check_admin_referer(self::NONCE_ACTION);

        $raw_action = Params::string($_POST['tube_comments_action'] ?? '');
        $action     = sanitize_key($raw_action);
        $raw_ids    = isset($_POST['comment_ids']) ? (array) wp_unslash($_POST['comment_ids']) : [];
        $ids        = array_map(static fn ($id): int => Params::int($id), $raw_ids);

        $status_map = [
            'approve'   => 'published',
            'unapprove' => 'pending',
            'spam'      => 'spam',
            'restore'   => 'published',
            'delete'    => 'deleted',
        ];

        if (isset($status_map[ $action ])) {
            foreach ($ids as $id) {
                $this->service->set_status_as_moderator($id, $status_map[ $action ]);
            }
        }

        $redirect = wp_get_referer();

        wp_safe_redirect(false !== $redirect ? $redirect : admin_url('admin.php?page=tube-comments'));
        exit;
    }

    /**
     * Renders the moderation screen.
     */
    public function render(): void
    {
        if (! current_user_can('moderate_comments')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filters (status/search/page), not a state-changing action; the only mutating action on this screen is handle_action(), which does verify a nonce (check_admin_referer() above).
        $raw_status    = isset($_GET['status']) ? Params::string($_GET['status']) : 'all';
        $status_filter = sanitize_key($raw_status);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        $raw_search = isset($_GET['s']) ? Params::string(wp_unslash($_GET['s'])) : '';
        $search     = sanitize_text_field($raw_search);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        $paged  = isset($_GET['paged']) ? max(1, Params::int($_GET['paged'])) : 1;
        $offset = ($paged - 1) * self::PAGE_SIZE;

        $repository        = new CommentRepository();
        $report_repository = new CommentReportRepository();

        if ('reported' === $status_filter) {
            $ids  = $report_repository->reported_comment_ids(self::PAGE_SIZE, $offset);
            $rows = array_values(array_filter(array_map([$repository, 'find'], $ids)));
        } else {
            $rows = $repository->list_for_moderation($status_filter, $search, self::PAGE_SIZE, $offset);
        }

        $total_pages = 'reported' === $status_filter
            ? $paged + (count($rows) === self::PAGE_SIZE ? 1 : 0)
            : (int) ceil($repository->count_for_moderation($status_filter, $search) / self::PAGE_SIZE);

        $tabs = [
            'all'       => __('Tất cả', 'tube-comments'),
            'published' => __('Đã duyệt', 'tube-comments'),
            'pending'   => __('Chờ duyệt', 'tube-comments'),
            'reported'  => __('Bị báo cáo', 'tube-comments'),
            'spam'      => __('Spam', 'tube-comments'),
            'deleted'   => __('Đã xóa', 'tube-comments'),
        ];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Tube Comments', 'tube-comments'); ?></h1>

            <ul class="subsubsub">
                <?php foreach ($tabs as $key => $label) : ?>
                    <li>
                        <a
                            href="<?php echo esc_url(admin_url('admin.php?page=tube-comments&status=' . $key)); ?>"
                            class="<?php echo $status_filter === $key ? 'current' : ''; ?>"
                        ><?php echo esc_html($label); ?></a>
                        <?php echo 'deleted' !== $key ? ' |' : ''; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <form method="get">
                <input type="hidden" name="page" value="tube-comments">
                <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
                <p class="search-box">
                    <input
                        type="search"
                        name="s"
                        value="<?php echo esc_attr($search); ?>"
                        placeholder="<?php echo esc_attr__('Tìm nội dung...', 'tube-comments'); ?>"
                    >
                    <?php submit_button(__('Tìm', 'tube-comments'), '', '', false); ?>
                </p>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tube_comments_moderate">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>

                <div class="tablenav top">
                    <select name="tube_comments_action">
                        <option value=""><?php esc_html_e('Hành động hàng loạt', 'tube-comments'); ?></option>
                        <option value="approve"><?php esc_html_e('Duyệt', 'tube-comments'); ?></option>
                        <option value="unapprove"><?php esc_html_e('Bỏ duyệt', 'tube-comments'); ?></option>
                        <option value="spam"><?php esc_html_e('Đánh dấu Spam', 'tube-comments'); ?></option>
                        <option value="restore"><?php esc_html_e('Khôi phục', 'tube-comments'); ?></option>
                        <option value="delete"><?php esc_html_e('Xóa', 'tube-comments'); ?></option>
                    </select>
                    <?php submit_button(__('Áp dụng', 'tube-comments'), 'action', '', false); ?>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" data-tube-comments-select-all>
                            </td>
                            <th><?php esc_html_e('Bình luận', 'tube-comments'); ?></th>
                            <th><?php esc_html_e('Người dùng', 'tube-comments'); ?></th>
                            <th><?php esc_html_e('Video', 'tube-comments'); ?></th>
                            <th><?php esc_html_e('Ngày', 'tube-comments'); ?></th>
                            <th><?php esc_html_e('Lượt thích', 'tube-comments'); ?></th>
                            <th><?php esc_html_e('Báo cáo', 'tube-comments'); ?></th>
                            <th><?php esc_html_e('Trạng thái', 'tube-comments'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ([] === $rows) : ?>
                            <tr>
                                <td colspan="8"><?php esc_html_e('Không có bình luận nào.', 'tube-comments'); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row) : ?>
                            <?php
                            $comment_id      = Params::int($row['id']);
                            $author_id       = Params::int($row['user_id']);
                            $video_id        = Params::int($row['video_id']);
                            $content_raw     = Params::string($row['content']);
                            $created_at_raw  = Params::string($row['created_at']);
                            $likes_text      = Params::string($row['likes_total']);
                            $status_text     = Params::string($row['status']);
                            $comment_id_text = (string) $comment_id;

                            $author        = get_userdata($author_id);
                            $post          = get_post($video_id);
                            $reports_count = $report_repository->count_for_comment($comment_id);
                            $reports_text  = (string) $reports_count;
                            $author_name   = $author instanceof WP_User ? $author->display_name : '—';
                            $content_line  = wp_trim_words($content_raw, 20);
                            $created_date  = get_date_from_gmt($created_at_raw, 'Y-m-d H:i');
                            ?>
                            <tr>
                                <th class="check-column">
                                    <input
                                        type="checkbox"
                                        name="comment_ids[]"
                                        value="<?php echo esc_attr($comment_id_text); ?>"
                                    >
                                </th>
                                <td><?php echo esc_html($content_line); ?></td>
                                <td><?php echo esc_html($author_name); ?></td>
                                <td>
                                    <?php if ($post instanceof WP_Post) : ?>
                                        <a
                                            href="<?php echo esc_url(get_permalink($post)); ?>"
                                            target="_blank"
                                            rel="noopener"
                                        >
                                            <?php echo esc_html($post->post_title); ?>
                                        </a>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($created_date); ?></td>
                                <td><?php echo esc_html($likes_text); ?></td>
                                <td><?php echo esc_html($reports_text); ?></td>
                                <td><?php echo esc_html($status_text); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1) : ?>
                    <?php
                    $pagination_links = paginate_links(
                        [
                            'base'    => add_query_arg('paged', '%#%'),
                            'format'  => '',
                            'current' => $paged,
                            'total'   => max(1, $total_pages),
                        ]
                    );
                    ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <?php echo wp_kses_post($pagination_links); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }
}
