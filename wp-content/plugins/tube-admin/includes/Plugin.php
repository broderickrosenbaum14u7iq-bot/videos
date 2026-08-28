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
use Tube_Admin\Notices\ImportFailureNotice;
use Tube_Admin\Settings\SettingsScreen;
use Tube_Admin\Statistics\StatisticsDashboardScreen;
use Tube_Admin\Status\SystemStatusScreen;
use Tube_Admin\Video\PosterImageMetaBox;
use Tube_Admin\Video\StreamUidMetaBox;
use Tube_Admin\Video\VideoDetailsScreen;
use Tube_Core\Plugin as Tube_Core_Plugin;

/**
 * Tube Admin's bootstrap: registers the wp-admin menu/screens and wires
 * the small tube-admin-owned service (`AssignmentService`) that sits on
 * top of tube-core's public API. Same lazy-singleton composition-root
 * shape as every other `tube-*` plugin's `Plugin.php` — see
 * `Tube_Core\Plugin`'s own docblock for the pattern this follows.
 *
 * Since ADR-0001 (`adr/0001-media-library-poster-images.md`), poster/OG
 * image uploads go through WordPress's own native media library
 * (`wp.media()`) rather than a bespoke Cloudflare Images upload service —
 * the `image_uploader()`/`poster_upload_service()` accessors this class
 * previously exposed, and the `CloudflareImagesUploader`/
 * `PosterUploadService`/`ImageUploaderInterface`/`ImageUploadException`
 * classes they wired up, are removed as part of that change: they had no
 * remaining caller.
 *
 * Since the ADR's 2026-08-25 addendum, the poster picker itself
 * (`Tube_Admin\Video\PosterImageMetaBox`) lives on WordPress's native
 * `Videos → Add New`/`Edit Video` screen, the same one-obvious-surface
 * treatment `StreamUidMetaBox` already established for the Cloudflare
 * Stream UID — `VideoDetailsScreen` no longer edits `poster_image_id`
 * at all (read-only display + a link), keeping only `og_image_id` (a
 * different field) and actor/studio assignment as its own concern.
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
        StreamUidMetaBox::register();
        // Registered after StreamUidMetaBox — both hook save_post_video at
        // the same default priority, so registration order is call order;
        // PosterImageMetaBox::save() depends on the metadata row
        // StreamUidMetaBox::save() may have just created in the same request.
        PosterImageMetaBox::register();
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
}
