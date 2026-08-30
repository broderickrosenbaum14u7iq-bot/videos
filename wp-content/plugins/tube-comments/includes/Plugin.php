<?php
/**
 * Tube Comments' bootstrap.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments;

use Predis\Client;
use Tube_Comments\Admin\ModerationScreen;
use Tube_Comments\Comments\AntiSpam\SpamGuard;
use Tube_Comments\Comments\CommentService;
use Tube_Comments\Comments\Repositories\CommentCounterRepository;
use Tube_Comments\Comments\Repositories\CommentLikeRepository;
use Tube_Comments\Comments\Repositories\CommentReportRepository;
use Tube_Comments\Comments\Repositories\CommentRepository;
use Tube_Comments\Comments\Repositories\CommentRootLockRepository;
use Tube_Comments\Events\VideoDeletionCascadeSubscriber;
use Tube_Comments\Http\CommentCreateController;
use Tube_Comments\Http\CommentDeleteController;
use Tube_Comments\Http\CommentLikeController;
use Tube_Comments\Http\CommentListController;
use Tube_Comments\Http\CommentMineController;
use Tube_Comments\Http\CommentRepliesController;
use Tube_Comments\Http\CommentReportController;
use Tube_Comments\Http\CommentUpdateController;
use Tube_Comments\Render\CommentsSectionRenderer;
use Tube_Comments\SchemaMigrations\Migration001CreateCommentsTable;
use Tube_Comments\SchemaMigrations\Migration002CreateCommentLikesTable;
use Tube_Comments\SchemaMigrations\Migration003CreateCommentReportsTable;
use Tube_Comments\SchemaMigrations\Migration004CreateCommentCountersTable;
use Tube_Comments\SchemaMigrations\Migration005CreateCommentRootLocksTable;
use Tube_Comments\Support\RedisRateLimiter;
use Tube_Core\Plugin as Tube_Core_Plugin;

/**
 * Tube Comments' bootstrap: comments, one-level replies, comment likes,
 * reports, moderation, and timestamp links, per the P0-P1 comment system
 * build (2026-08-26).
 *
 * Storage decision — dedicated tables, not `wp_comments` — documented
 * here rather than scattered across each repository:
 *
 * `wp_comments`'s native shape (`comment_post_ID`, `comment_parent`,
 * `comment_approved`, `comment_type`) was evaluated first and rejected
 * for four concrete, evidence-based reasons specific to this project's
 * stated scale (100k videos, millions of comments):
 *
 * 1. No popularity index. `wp_comments` has no comment-likes concept at
 *    all — "Phổ biến" sort would need either a `commentmeta` join (no
 *    usable composite index; `wp_commentmeta` is `comment_id`/`meta_key`
 *    keyed, not `comment_post_ID`-aware) or an application-side sort
 *    after fetching every comment for a video, which stops scaling long
 *    before 1M comments.
 * 2. Type/isolation tax on every query. This project's `wp_comments`
 *    table (if used) would need `comment_type = 'tube_comment'` on every
 *    single query to stay isolated from any other WordPress comment
 *    usage (plugins, imports, admin tooling) — a filter condition that
 *    exists purely for isolation, not because the domain needs it,
 *    unlike a dedicated table which needs no such discriminator at all.
 * 3. Reply-depth mismatch. `wp_comments`' `comment_parent` supports
 *    unlimited nesting depth, which is exactly the wrong default for
 *    Phase 15's "one visible nested level only" — every read would need
 *    to walk and re-flatten an arbitrary-depth tree at render time.
 *    A dedicated table encodes the one-level invariant directly: replies
 *    always store `parent_id` = the ROOT comment's ID, never an
 *    intermediate reply's ID (see `Migration001CreateCommentsTable`).
 * 4. No comment-likes/reports primitive. Both are new domain concepts
 *    `wp_comments` has no columns or sibling tables for; they would need
 *    new custom tables regardless of the base storage choice, at which
 *    point isolating comments themselves the same way removes the
 *    type-discrimination tax from every single comment query for free.
 *
 * Custom tables were not chosen "because they seem cleaner" — every
 * index in `Migration001CreateCommentsTable` maps directly to one of the
 * three real read patterns this UI issues (root comments by recency,
 * root comments by popularity, one root's replies), which is the
 * evidence this decision rests on.
 *
 * Depends on tube-core at runtime only (its `AbstractMigration` and the
 * project's shared `tube/v1` REST namespace) and, for comment-author
 * display, on tube-members' template tags (`tube_members_get_avatar_url()`
 * etc.) — never the reverse, so tube-members has zero knowledge of
 * tube-comments (no circular dependency).
 */
final class Plugin
{
    /**
     * The shared Plugin instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Lazily created by self::rate_limiter().
     *
     * @var RedisRateLimiter|null
     */
    private ?RedisRateLimiter $rate_limiter = null;

    /**
     * Lazily created by self::comment_repository().
     *
     * @var CommentRepository|null
     */
    private ?CommentRepository $comment_repository = null;

    /**
     * Lazily created by self::comment_like_repository().
     *
     * @var CommentLikeRepository|null
     */
    private ?CommentLikeRepository $comment_like_repository = null;

    /**
     * Lazily created by self::comment_report_repository().
     *
     * @var CommentReportRepository|null
     */
    private ?CommentReportRepository $comment_report_repository = null;

    /**
     * Lazily created by self::comment_counter_repository().
     *
     * @var CommentCounterRepository|null
     */
    private ?CommentCounterRepository $comment_counter_repository = null;

    /**
     * Lazily created by self::comment_root_lock_repository().
     *
     * @var CommentRootLockRepository|null
     */
    private ?CommentRootLockRepository $comment_root_lock_repository = null;

    /**
     * Lazily created by self::comment_service().
     *
     * @var CommentService|null
     */
    private ?CommentService $comment_service = null;

    /**
     * Private: use {@see self::instance()}.
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
        Tube_Core_Plugin::instance()->migration_runner()->register_source(
            'tube-comments',
            [
                Migration001CreateCommentsTable::class,
                Migration002CreateCommentLikesTable::class,
                Migration003CreateCommentReportsTable::class,
                Migration004CreateCommentCountersTable::class,
                Migration005CreateCommentRootLocksTable::class,
            ]
        );

        $section_renderer = new CommentsSectionRenderer();

        add_action('wp_enqueue_scripts', [$section_renderer, 'enqueue_assets']);

        $moderation_screen = new ModerationScreen($this->comment_service());

        add_action('admin_menu', [$moderation_screen, 'register_menu']);
        add_action('admin_post_tube_comments_moderate', [$moderation_screen, 'handle_action']);

        (new VideoDeletionCascadeSubscriber())->register();

        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Register this plugin's REST routes under the shared `tube/v1`
     * namespace. Every write route requires a logged-in member
     * (`is_user_logged_in()`) plus, inside each controller, a valid
     * `X-WP-Nonce` (`wp_verify_nonce($nonce, 'wp_rest')` — WordPress
     * core's own cookie-auth REST nonce action, the same one
     * `wp_localize_script()`-supplied nonces already use site-wide) —
     * comments are a genuine cross-user-visible, account-mutating action
     * (unlike the video like/save toggles' public/no-nonce posture),
     * so this is deliberately the stricter posture, matching Phase 12's
     * "only authenticated frontend members" + Phase 20's CSRF concern.
     */
    public function register_rest_routes(): void
    {
        $list_controller = new CommentListController($this->comment_repository());

        register_rest_route(
            'tube/v1',
            '/videos/(?P<id>\d+)/comments',
            [
                'methods'             => 'GET',
                'callback'            => [$list_controller, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );

        $replies_controller = new CommentRepliesController($this->comment_repository());

        register_rest_route(
            'tube/v1',
            '/comments/(?P<id>\d+)/replies',
            [
                'methods'             => 'GET',
                'callback'            => [$replies_controller, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );

        $create_controller = new CommentCreateController($this->comment_service(), $this->rate_limiter());

        register_rest_route(
            'tube/v1',
            '/videos/(?P<id>\d+)/comments',
            [
                'methods'             => 'POST',
                'callback'            => [$create_controller, 'handle'],
                'permission_callback' => static fn (): bool => is_user_logged_in(),
            ]
        );

        $update_controller = new CommentUpdateController($this->comment_service());

        register_rest_route(
            'tube/v1',
            '/comments/(?P<id>\d+)',
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [$update_controller, 'handle'],
                    'permission_callback' => static fn (): bool => is_user_logged_in(),
                ],
            ]
        );

        $delete_controller = new CommentDeleteController($this->comment_service());

        register_rest_route(
            'tube/v1',
            '/comments/(?P<id>\d+)/delete',
            [
                'methods'             => 'POST',
                'callback'            => [$delete_controller, 'handle'],
                'permission_callback' => static fn (): bool => is_user_logged_in(),
            ]
        );

        $like_controller = new CommentLikeController(
            $this->comment_like_repository(),
            $this->comment_repository(),
            $this->rate_limiter()
        );

        register_rest_route(
            'tube/v1',
            '/comments/(?P<id>\d+)/like',
            [
                'methods'             => 'POST',
                'callback'            => [$like_controller, 'handle'],
                'permission_callback' => static fn (): bool => is_user_logged_in(),
            ]
        );

        $report_controller = new CommentReportController($this->comment_report_repository(), $this->rate_limiter());

        register_rest_route(
            'tube/v1',
            '/comments/(?P<id>\d+)/report',
            [
                'methods'             => 'POST',
                'callback'            => [$report_controller, 'handle'],
                'permission_callback' => static fn (): bool => is_user_logged_in(),
            ]
        );

        $mine_controller = new CommentMineController($this->comment_repository());

        register_rest_route(
            'tube/v1',
            '/comments/mine',
            [
                'methods'             => 'GET',
                'callback'            => [$mine_controller, 'handle'],
                'permission_callback' => static fn (): bool => is_user_logged_in(),
            ]
        );
    }

    /**
     * This plugin's own rate limiter — see `Tube_Members\Plugin::rate_limiter()`'s
     * docblock for why this is a separate instance rather than a shared one.
     */
    public function rate_limiter(): RedisRateLimiter
    {
        if (null !== $this->rate_limiter) {
            return $this->rate_limiter;
        }

        $host     = defined('TUBE_CORE_REDIS_HOST') ? TUBE_CORE_REDIS_HOST : '127.0.0.1';
        $port     = defined('TUBE_CORE_REDIS_PORT') ? TUBE_CORE_REDIS_PORT : 6379;
        $database = defined('TUBE_CORE_REDIS_DB') ? TUBE_CORE_REDIS_DB : 0;

        $this->rate_limiter = new RedisRateLimiter(
            new Client(
                [
                    'host'     => $host,
                    'port'     => $port,
                    'database' => $database,
                ]
            )
        );

        return $this->rate_limiter;
    }

    /**
     * The `wp_tube_comments` data-access layer.
     */
    public function comment_repository(): CommentRepository
    {
        if (null === $this->comment_repository) {
            $this->comment_repository = new CommentRepository();
        }

        return $this->comment_repository;
    }

    /**
     * The `wp_tube_comment_likes` data-access layer.
     */
    public function comment_like_repository(): CommentLikeRepository
    {
        if (null === $this->comment_like_repository) {
            $this->comment_like_repository = new CommentLikeRepository();
        }

        return $this->comment_like_repository;
    }

    /**
     * The `wp_tube_comment_reports` data-access layer.
     */
    public function comment_report_repository(): CommentReportRepository
    {
        if (null === $this->comment_report_repository) {
            $this->comment_report_repository = new CommentReportRepository();
        }

        return $this->comment_report_repository;
    }

    /**
     * The `wp_tube_comment_counters` data-access layer.
     */
    public function comment_counter_repository(): CommentCounterRepository
    {
        if (null === $this->comment_counter_repository) {
            $this->comment_counter_repository = new CommentCounterRepository();
        }

        return $this->comment_counter_repository;
    }

    /**
     * The `wp_tube_comment_root_locks` data-access layer — the atomic
     * one-root-comment-per-video-per-24h slot table `SpamGuard` enforces
     * creation through.
     */
    public function comment_root_lock_repository(): CommentRootLockRepository
    {
        if (null === $this->comment_root_lock_repository) {
            $this->comment_root_lock_repository = new CommentRootLockRepository();
        }

        return $this->comment_root_lock_repository;
    }

    /**
     * The comment domain service: create/edit/delete, status/spam
     * heuristics, and keeping `wp_tube_comment_counters` /
     * `replies_total` in sync.
     */
    public function comment_service(): CommentService
    {
        if (null === $this->comment_service) {
            $this->comment_service = new CommentService(
                $this->comment_repository(),
                $this->comment_counter_repository(),
                new SpamGuard($this->comment_repository(), $this->comment_root_lock_repository())
            );
        }

        return $this->comment_service;
    }
}
