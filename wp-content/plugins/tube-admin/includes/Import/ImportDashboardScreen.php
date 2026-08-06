<?php
/**
 * The import queue status/progress dashboard and manual trigger/retry controls.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Import;

use Tube_Admin\Plugin;
use Tube_Admin\Support\Request;
use Tube_Core\Import\BatchProcessor;
use Tube_Core\Import\ImportStatus;
use Tube_Core\Import\Repositories\ImportQueueRepository;
use Tube_Core\Import\VideoImporter;
use Tube_Core\Plugin as Tube_Core_Plugin;

/**
 * The `wp-admin` surface over `tube-core`'s existing
 * `wp_tube_import_queue` table (Phase 5's `ImportQueueRepository`/
 * `BatchProcessor`): status/progress visibility (this phase's "queue
 * monitor" deliverable is the stat tiles + failure-rate visible here,
 * folded into this one screen rather than a second near-duplicate page —
 * see `PHASE-10.md`'s design decisions) plus manual "process next batch"
 * and per-row "retry" controls.
 *
 * WordPress-coupled throughout (constructs real `tube-core` repositories/
 * services, reads `$_GET`/`$_POST`) and integration/live-tested only, the
 * same split every other screen in this plugin uses.
 */
final class ImportDashboardScreen
{
    /**
     * This screen's menu slug — also the top-level "Tube Admin" menu's slug.
     */
    public const SLUG = 'tube-admin';

    /**
     * Nonce action for the manual "process next batch" trigger.
     */
    private const PROCESS_NONCE_ACTION = 'tube_admin_process_import_batch';

    /**
     * Nonce action for a per-row "retry" action.
     */
    private const RETRY_NONCE_ACTION = 'tube_admin_retry_import_item';

    /**
     * How many pending items a manual "process next batch" click claims at once.
     */
    private const MANUAL_BATCH_SIZE = 20;

    /**
     * How many rows the queue table shows per page.
     */
    private const PER_PAGE = 20;

    /**
     * Register this screen's `admin-post.php` action handlers. Called
     * once from `Tube_Admin\Plugin::boot()`.
     */
    public static function register_actions(): void
    {
        add_action('admin_post_tube_admin_process_import_batch', [self::class, 'handle_process_batch']);
        add_action('admin_post_tube_admin_retry_import_item', [self::class, 'handle_retry']);
    }

    /**
     * Render the screen.
     */
    public function render(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'tube-admin'));
        }

        $repository = new ImportQueueRepository();
        $counts     = $repository->status_counts();

        $status_filter = $this->status_from_request();
        $paged         = $this->paged_from_request();
        $items         = $repository->list_items($status_filter, self::PER_PAGE, ($paged - 1) * self::PER_PAGE);
        $total         = $repository->count_items($status_filter);

        // The view calls get_edit_post_link() per completed row -- batch-
        // hydrate the post cache first so that's one query for the whole
        // page instead of one per row, the same _prime_post_caches()
        // technique Tube_Seo\Sitemap\SitemapGenerator already established.
        $video_ids = array_values(array_filter(array_column($items, 'video_id')));
        _prime_post_caches($video_ids, false, false);

        require __DIR__ . '/views/dashboard.php';
    }

    /**
     * `admin-post.php` handler: claim and process up to
     * {@see self::MANUAL_BATCH_SIZE} pending items synchronously, then
     * redirect back with a result summary.
     */
    public static function handle_process_batch(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'tube-admin'));
        }

        check_admin_referer(self::PROCESS_NONCE_ACTION);

        $queue_repository = new ImportQueueRepository();
        $processor        = new BatchProcessor(
            $queue_repository,
            new VideoImporter(Tube_Core_Plugin::instance()->video_metadata_repository()),
            Tube_Core_Plugin::instance()->events()
        );

        $result = $processor->process(self::MANUAL_BATCH_SIZE);

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'      => self::SLUG,
                    'processed' => (string) ($result['completed'] + $result['retried'] + $result['failed']),
                    'completed' => (string) $result['completed'],
                    'failed'    => (string) $result['failed'],
                ],
                admin_url('admin.php')
            )
        );

        exit;
    }

    /**
     * `admin-post.php` handler: reset one permanently-failed item back to
     * pending for an immediate manual retry, then redirect back.
     */
    public static function handle_retry(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'tube-admin'));
        }

        check_admin_referer(self::RETRY_NONCE_ACTION);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce covering this entire request was already verified by check_admin_referer() immediately above.
        $id = absint(wp_unslash(Request::string($_POST, 'item_id')));

        $requeued = 0 !== $id && (new ImportQueueRepository())->requeue($id);

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'     => self::SLUG,
                    'requeued' => $requeued ? '1' : '0',
                ],
                admin_url('admin.php')
            )
        );

        exit;
    }

    /**
     * The status filter from the current request's `status` query arg, if valid.
     */
    private function status_from_request(): ?ImportStatus
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a GET-only view filter, not a state-changing request; nothing here writes data.
        $raw = sanitize_key(wp_unslash(Request::string($_GET, 'status')));

        return ImportStatus::tryFrom($raw);
    }

    /**
     * The current page number from the request's `paged` query arg, minimum 1.
     */
    private function paged_from_request(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a GET-only pagination control, not a state-changing request.
        $paged = absint(wp_unslash(Request::string($_GET, 'paged', '1')));

        return max(1, $paged);
    }
}
