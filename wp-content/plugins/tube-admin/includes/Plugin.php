<?php
/**
 * Tube Admin's bootstrap.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin;

use Tube_Admin\Assignment\AssignmentService;
use Tube_Admin\Bulk\BulkToolsScreen;
use Tube_Admin\Import\ImportDashboardScreen;
use Tube_Admin\Media\CloudflareImagesUploader;
use Tube_Admin\Media\ImageUploaderInterface;
use Tube_Admin\Media\PosterUploadService;
use Tube_Admin\Notices\ImportFailureNotice;
use Tube_Admin\Settings\SettingsScreen;
use Tube_Admin\Statistics\StatisticsDashboardScreen;
use Tube_Admin\Status\SystemStatusScreen;
use Tube_Admin\Video\VideoDetailsScreen;
use Tube_Core\Plugin as Tube_Core_Plugin;

/**
 * Tube Admin's bootstrap: registers the wp-admin menu/screens and wires
 * the two small tube-admin-owned services (`AssignmentService`,
 * `PosterUploadService`) that sit on top of tube-core's public API. Same
 * lazy-singleton composition-root shape as every other `tube-*` plugin's
 * `Plugin.php` — see `Tube_Core\Plugin`'s own docblock for the pattern
 * this follows.
 */
final class Plugin
{
    /**
     * The single WordPress capability every tube-admin screen and action
     * requires. tube-admin is an operational area (import pipeline,
     * cross-video bulk edits, system configuration) rather than
     * per-post editorial work, so it is gated at the site-administrator
     * level uniformly, rather than modeling a per-screen capability
     * matrix ARCHITECTURE.md/DEVELOPMENT_RULES.md don't specify.
     */
    public const CAPABILITY = 'manage_options';

    /**
     * The shared instance, lazily created by self::instance().
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Lazily created by self::assignment_service().
     *
     * @var AssignmentService|null
     */
    private ?AssignmentService $assignment_service = null;

    /**
     * Lazily created by self::image_uploader().
     *
     * @var ImageUploaderInterface|null
     */
    private ?ImageUploaderInterface $image_uploader = null;

    /**
     * Lazily created by self::poster_upload_service().
     *
     * @var PosterUploadService|null
     */
    private ?PosterUploadService $poster_upload_service = null;

    /**
     * Private: use self::instance() instead.
     */
    private function __construct()
    {
    }

    /**
     * The shared Plugin instance.
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Wire up hooks. Called on `plugins_loaded`.
     */
    public function boot(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_notices', [new ImportFailureNotice(), 'render']);

        ImportDashboardScreen::register_actions();
        VideoDetailsScreen::register_actions();
        BulkToolsScreen::register_actions();
    }

    /**
     * Register the top-level "Tube Admin" menu and every submenu screen.
     * Called on `admin_menu`.
     */
    public function register_menu(): void
    {
        add_menu_page(
            __('Tube Admin', 'tube-admin'),
            __('Tube Admin', 'tube-admin'),
            self::CAPABILITY,
            ImportDashboardScreen::SLUG,
            [new ImportDashboardScreen(), 'render'],
            'dashicons-video-alt2',
            26
        );

        add_submenu_page(
            ImportDashboardScreen::SLUG,
            __('Import Queue', 'tube-admin'),
            __('Import Queue', 'tube-admin'),
            self::CAPABILITY,
            ImportDashboardScreen::SLUG,
            [new ImportDashboardScreen(), 'render']
        );

        add_submenu_page(
            ImportDashboardScreen::SLUG,
            __('Statistics', 'tube-admin'),
            __('Statistics', 'tube-admin'),
            self::CAPABILITY,
            StatisticsDashboardScreen::SLUG,
            [new StatisticsDashboardScreen(), 'render']
        );

        add_submenu_page(
            ImportDashboardScreen::SLUG,
            __('Video Details', 'tube-admin'),
            __('Video Details', 'tube-admin'),
            self::CAPABILITY,
            VideoDetailsScreen::SLUG,
            [new VideoDetailsScreen(), 'render']
        );

        add_submenu_page(
            ImportDashboardScreen::SLUG,
            __('Bulk Tools', 'tube-admin'),
            __('Bulk Tools', 'tube-admin'),
            self::CAPABILITY,
            BulkToolsScreen::SLUG,
            [new BulkToolsScreen(), 'render']
        );

        add_submenu_page(
            ImportDashboardScreen::SLUG,
            __('System Status', 'tube-admin'),
            __('System Status', 'tube-admin'),
            self::CAPABILITY,
            SystemStatusScreen::SLUG,
            [new SystemStatusScreen(), 'render']
        );

        add_submenu_page(
            ImportDashboardScreen::SLUG,
            __('Settings', 'tube-admin'),
            __('Settings', 'tube-admin'),
            self::CAPABILITY,
            SettingsScreen::SLUG,
            [new SettingsScreen(), 'render']
        );
    }

    /**
     * The actor/studio assignment orchestrator.
     *
     * Public: `VideoDetailsScreen`/`BulkToolsScreen` both use it.
     */
    public function assignment_service(): AssignmentService
    {
        if (null === $this->assignment_service) {
            $this->assignment_service = new AssignmentService(
                Tube_Core_Plugin::instance()->actor_repository(),
                Tube_Core_Plugin::instance()->studio_repository(),
                Tube_Core_Plugin::instance()->events()
            );
        }

        return $this->assignment_service;
    }

    /**
     * The Cloudflare Images uploader, per ARCHITECTURE.md §8.
     *
     * Public so `poster_upload_service()`'s construction is visible here
     * at the composition root, and so tests/future consumers can swap it.
     */
    public function image_uploader(): ImageUploaderInterface
    {
        if (null === $this->image_uploader) {
            $account_id = defined('TUBE_ADMIN_CLOUDFLARE_IMAGES_ACCOUNT_ID')
                && is_string(TUBE_ADMIN_CLOUDFLARE_IMAGES_ACCOUNT_ID)
                ? TUBE_ADMIN_CLOUDFLARE_IMAGES_ACCOUNT_ID
                : '';
            $api_token  = defined('TUBE_ADMIN_CLOUDFLARE_IMAGES_API_TOKEN')
                && is_string(TUBE_ADMIN_CLOUDFLARE_IMAGES_API_TOKEN)
                ? TUBE_ADMIN_CLOUDFLARE_IMAGES_API_TOKEN
                : '';

            $this->image_uploader = new CloudflareImagesUploader($account_id, $api_token);
        }

        return $this->image_uploader;
    }

    /**
     * The poster/OG image upload-and-replace orchestrator.
     *
     * Public: `VideoDetailsScreen` uses it.
     */
    public function poster_upload_service(): PosterUploadService
    {
        if (null === $this->poster_upload_service) {
            $this->poster_upload_service = new PosterUploadService($this->image_uploader());
        }

        return $this->poster_upload_service;
    }
}
