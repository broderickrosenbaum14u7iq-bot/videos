<?php
/**
 * Site-wide admin notice when import queue items have permanently failed.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Notices;

use Tube_Admin\Import\ImportDashboardScreen;
use Tube_Admin\Plugin;
use Tube_Core\Import\ImportStatus;
use Tube_Core\Import\Repositories\ImportQueueRepository;

/**
 * A real, data-backed `admin_notices` warning shown wherever a capable
 * user is in `wp-admin` (not just on tube-admin's own screens, since a
 * stuck import backlog is exactly the kind of thing an admin should
 * learn about without having to already know to go look for it) when
 * `wp_tube_import_queue` has one or more permanently-`failed` items —
 * never decorative, never shown when the count is genuinely zero.
 */
final class ImportFailureNotice
{
    /**
     * Render the notice, if there is anything to report. Hooked to `admin_notices`.
     */
    public function render(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            return;
        }

        $failed_count = (new ImportQueueRepository())->count_items(ImportStatus::Failed);

        if ($failed_count < 1) {
            return;
        }

        $dashboard_url = add_query_arg(
            [
                'page'   => ImportDashboardScreen::SLUG,
                'status' => ImportStatus::Failed->value,
            ],
            admin_url('admin.php')
        );
        ?>
        <?php
        /* translators: 1: number of permanently-failed import items, 2: link to the import dashboard */
        $tube_admin_format = _n(
            '%1$d video import has permanently failed. %2$s',
            '%1$d video imports have permanently failed. %2$s',
            $failed_count,
            'tube-admin'
        );

        $tube_admin_link = '<a href="' . esc_url($dashboard_url) . '">' . esc_html__('Review', 'tube-admin') . '</a>';

        $tube_admin_message = sprintf($tube_admin_format, $failed_count, $tube_admin_link);
        ?>
        <div class="notice notice-error">
            <p>
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $tube_admin_message is assembled just above from a trusted static translator string plus two already-escaped dynamic parts ($failed_count is an int, the link built via esc_url()/esc_html()).
                echo $tube_admin_message;
                ?>
            </p>
        </div>
        <?php
    }
}
