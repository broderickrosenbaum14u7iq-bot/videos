<?php
/**
 * The video statistics dashboard.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Statistics;

use Tube_Admin\Plugin;
use Tube_Admin\Support\Request;
use Tube_Core\Plugin as Tube_Core_Plugin;

/**
 * The `wp-admin` surface over `tube-core`'s existing
 * `wp_tube_video_statistics` table (Phase 4): a sortable, paginated table
 * of every video's `views_total`/`views_today`/`views_7d`/`views_30d`,
 * backed by `VideoStatisticsRepository::list_all()` (Phase 10 addition —
 * no aggregation logic lives here, `StatsRollup` already computes these
 * columns). WordPress-coupled throughout and integration/live-tested
 * only, the same split every other screen in this plugin uses.
 */
final class StatisticsDashboardScreen
{
    /**
     * This screen's menu slug.
     */
    public const SLUG = 'tube-admin-statistics';

    /**
     * How many rows the table shows per page.
     */
    private const PER_PAGE = 20;

    /**
     * Columns the table may be sorted by, and their display labels.
     *
     * @var array<string, string>
     */
    private const SORTABLE_COLUMNS = [
        'views_total' => 'Total Views',
        'views_today' => 'Today',
        'views_7d'    => '7 Days',
        'views_30d'   => '30 Days',
    ];

    /**
     * Render the screen.
     */
    public function render(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'tube-admin'));
        }

        $repository = Tube_Core_Plugin::instance()->video_statistics_repository();

        $order_by = $this->order_by_from_request();
        $paged    = $this->paged_from_request();
        $rows     = $repository->list_all($order_by, self::PER_PAGE, ($paged - 1) * self::PER_PAGE);
        $total    = $repository->count_all();

        // The view calls get_the_title()/get_edit_post_link() per row --
        // batch-hydrate the post cache first so that's one query for the
        // whole page instead of one per row, the same _prime_post_caches()
        // technique Tube_Seo\Sitemap\SitemapGenerator already established
        // for this exact "titles for a list of already-known IDs" case.
        _prime_post_caches(array_column($rows, 'video_id'), false, false);

        require __DIR__ . '/views/dashboard.php';
    }

    /**
     * The sort column from the request's `orderby` query arg, defaulting
     * to `views_total`, constrained to the known-safe whitelist.
     *
     * @return 'views_total'|'views_today'|'views_7d'|'views_30d'
     */
    private function order_by_from_request(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a GET-only sort control, not a state-changing request.
        $raw = sanitize_key(wp_unslash(Request::string($_GET, 'orderby', 'views_total')));

        return array_key_exists($raw, self::SORTABLE_COLUMNS) ? $raw : 'views_total';
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
