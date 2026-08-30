<?php
/**
 * Tube Members' bootstrap.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members;

use Predis\Client;
use Tube_Members\Auth\AuthSessionService;
use Tube_Members\Auth\ForgotPasswordController;
use Tube_Members\Auth\LoginController;
use Tube_Members\Auth\LoginService;
use Tube_Members\Auth\LogoutController;
use Tube_Members\Auth\PasswordResetEmailSender;
use Tube_Members\Auth\PasswordResetService;
use Tube_Members\Auth\RegistrationController;
use Tube_Members\Auth\RegistrationService;
use Tube_Members\Auth\ResetPasswordController;
use Tube_Members\Capability\MemberRoleGuard;
use Tube_Members\Email\EmailVerificationService;
use Tube_Members\Email\ResendVerificationController;
use Tube_Members\Email\VerificationEmailSender;
use Tube_Members\OAuth\GoogleOAuthClient;
use Tube_Members\OAuth\GoogleOAuthController;
use Tube_Members\OAuth\GoogleSettingsScreen;
use Tube_Members\Profile\AvatarController;
use Tube_Members\Profile\AvatarService;
use Tube_Members\Profile\PasswordController;
use Tube_Members\Profile\ProfileController;
use Tube_Members\Render\HeaderAccountRenderer;
use Tube_Members\Routing\AccountRouting;
use Tube_Members\Routing\EmailVerificationRouting;
use Tube_Members\Routing\PasswordResetRouting;
use Tube_Members\Support\RedisRateLimiter;

/**
 * Tube Members' bootstrap: registration, login, logout, frontend
 * account, avatar, and Google OAuth architecture, per the P0-P2 member
 * system build (2026-08-26).
 *
 * Uses WordPress users (`wp_users`/`wp_usermeta`, core password hashing,
 * `wp_set_auth_cookie()`, core nonces) as the canonical account identity
 * — no second password database (Phase 3) — the same "WordPress-native
 * primitives, plugin-owned orchestration" split every other tube-*
 * plugin already uses for its own domain.
 *
 * No custom database tables: every piece of member state is
 * `wp_users`/`wp_usermeta` (avatar attachment ID, avatar source, Google
 * subject identifier for account-linking) or a WordPress core primitive
 * (rewrite rules, transients for OAuth state) — nothing here needs a
 * dedicated table the way tube-comments' domain does.
 *
 * Composition-root shape identical to `Tube_Core\Plugin`/
 * `Tube_Search\Plugin`: lazy-singleton accessors, hooks wired in
 * `boot()`, called once from `plugins_loaded`.
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
     * Lazily created by self::auth_session().
     *
     * @var AuthSessionService|null
     */
    private ?AuthSessionService $auth_session = null;

    /**
     * Lazily created by self::avatar_service().
     *
     * @var AvatarService|null
     */
    private ?AvatarService $avatar_service = null;

    /**
     * Lazily created by self::google_oauth_client().
     *
     * @var GoogleOAuthClient|null
     */
    private ?GoogleOAuthClient $google_oauth_client = null;

    /**
     * Lazily created by self::email_verification_service().
     *
     * @var EmailVerificationService|null
     */
    private ?EmailVerificationService $email_verification_service = null;

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
        $account_routing = new AccountRouting();

        add_action('init', [$account_routing, 'add_rewrite_rules']);
        add_filter('query_vars', [$account_routing, 'register_query_var']);
        add_filter('template_include', [$account_routing, 'route_template']);

        $email_verification_routing = new EmailVerificationRouting();

        add_action('init', [$email_verification_routing, 'add_rewrite_rules']);
        add_filter('query_vars', [$email_verification_routing, 'register_query_var']);
        add_filter('template_include', [$email_verification_routing, 'route_template']);

        $password_reset_routing = new PasswordResetRouting();

        add_action('init', [$password_reset_routing, 'add_rewrite_rules']);
        add_filter('query_vars', [$password_reset_routing, 'register_query_var']);
        add_filter('template_include', [$password_reset_routing, 'route_template']);

        $role_guard = new MemberRoleGuard();

        add_action('admin_init', [$role_guard, 'block_backend_access']);
        add_filter('show_admin_bar', [$role_guard, 'hide_admin_bar_for_members']);

        $header_renderer = new HeaderAccountRenderer();

        add_action('tube_members_render_header_account', [$header_renderer, 'render']);
        add_action('wp_footer', [$header_renderer, 'render_login_modal']);
        add_action('wp_enqueue_scripts', [$header_renderer, 'enqueue_assets']);

        add_action('rest_api_init', [$this, 'register_rest_routes']);

        $google_settings = new GoogleSettingsScreen();

        add_action('admin_menu', [$google_settings, 'register_menu']);
        add_action('admin_init', [$google_settings, 'register_settings']);
    }

    /**
     * Plugin activation: flush rewrite rules so `/tai-khoan/` resolves
     * immediately, the same `add_rewrite_rules()`-then-flush pattern
     * `Tube_Search\Plugin::activate()` already uses for its own routes.
     */
    public static function activate(): void
    {
        (new AccountRouting())->add_rewrite_rules();
        (new EmailVerificationRouting())->add_rewrite_rules();
        (new PasswordResetRouting())->add_rewrite_rules();
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation: flush rewrite rules so `/tai-khoan/` stops
     * resolving once this plugin is off, mirroring
     * `Tube_Search\Plugin::deactivate()`.
     */
    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    /**
     * Register this plugin's REST routes under the shared `tube/v1`
     * namespace, the same one every other tube-* plugin's own routes
     * live under (see `Tube_Core\Plugin::register_rest_routes()`).
     */
    public function register_rest_routes(): void
    {
        $registration_controller = new RegistrationController(
            new RegistrationService($this->rate_limiter()),
            $this->auth_session(),
            $this->email_verification_service()
        );

        register_rest_route(
            'tube/v1',
            '/auth/register',
            [
                'methods'             => 'POST',
                'callback'            => [$registration_controller, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );

        $login_controller = new LoginController(
            new LoginService($this->rate_limiter()),
            $this->auth_session(),
            $this->email_verification_service()
        );

        register_rest_route(
            'tube/v1',
            '/auth/login',
            [
                'methods'             => 'POST',
                'callback'            => [$login_controller, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );

        $forgot_password_controller = new ForgotPasswordController(
            new PasswordResetService($this->rate_limiter(), new PasswordResetEmailSender())
        );

        register_rest_route(
            'tube/v1',
            '/auth/forgot-password',
            [
                'methods'             => 'POST',
                'callback'            => [$forgot_password_controller, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );

        $reset_password_controller = new ResetPasswordController(
            new PasswordResetService($this->rate_limiter(), new PasswordResetEmailSender()),
            $this->auth_session(),
            $this->email_verification_service()
        );

        register_rest_route(
            'tube/v1',
            '/auth/reset-password',
            [
                'methods'             => 'POST',
                'callback'            => [$reset_password_controller, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );

        $logout_controller = new LogoutController($this->auth_session());

        register_rest_route(
            'tube/v1',
            '/auth/logout',
            [
                'methods'             => 'POST',
                'callback'            => [$logout_controller, 'handle'],
                'permission_callback' => static fn (): bool => is_user_logged_in(),
            ]
        );

        $google_client = $this->google_oauth_client();

        if (null !== $google_client) {
            $google_controller = new GoogleOAuthController(
                $google_client,
                $this->auth_session(),
                $this->email_verification_service()
            );

            register_rest_route(
                'tube/v1',
                '/auth/google/start',
                [
                    'methods'             => 'GET',
                    'callback'            => [$google_controller, 'start'],
                    'permission_callback' => '__return_true',
                ]
            );

            register_rest_route(
                'tube/v1',
                '/auth/google/callback',
                [
                    'methods'             => 'GET',
                    'callback'            => [$google_controller, 'callback'],
                    'permission_callback' => '__return_true',
                ]
            );
        }

        $profile_controller = new ProfileController();

        register_rest_route(
            'tube/v1',
            '/members/me',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$profile_controller, 'me'],
                    'permission_callback' => static fn (): bool => is_user_logged_in(),
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [$profile_controller, 'update_me'],
                    'permission_callback' => static fn (): bool => is_user_logged_in(),
                ],
            ]
        );

        $password_controller = new PasswordController();

        register_rest_route(
            'tube/v1',
            '/members/me/password',
            [
                'methods'             => 'POST',
                'callback'            => [$password_controller, 'handle'],
                'permission_callback' => static fn (): bool => is_user_logged_in(),
            ]
        );

        $avatar_controller = new AvatarController($this->avatar_service());

        register_rest_route(
            'tube/v1',
            '/members/me/avatar',
            [
                'methods'             => 'POST',
                'callback'            => [$avatar_controller, 'handle'],
                'permission_callback' => static fn (): bool => is_user_logged_in(),
            ]
        );

        $resend_verification_controller = new ResendVerificationController(
            $this->email_verification_service(),
            $this->rate_limiter()
        );

        register_rest_route(
            'tube/v1',
            '/members/me/resend-verification',
            [
                'methods'             => 'POST',
                'callback'            => [$resend_verification_controller, 'handle'],
                'permission_callback' => static fn (): bool => is_user_logged_in(),
            ]
        );
    }

    /**
     * The email-verification orchestration service, shared between the
     * REST controllers, the registration flow, Google OAuth, and the
     * `/xac-thuc-email/` route.
     */
    public function email_verification_service(): EmailVerificationService
    {
        if (null === $this->email_verification_service) {
            $this->email_verification_service = new EmailVerificationService(new VerificationEmailSender());
        }

        return $this->email_verification_service;
    }

    /**
     * This plugin's own rate limiter — `Tube_Core\Plugin`'s own copy is
     * `private` (not part of that class's external API), so this is a
     * separate instance against the same Redis server, the same
     * "each plugin owns its own copy of this small algorithm rather than
     * reaching into another plugin's internals" posture
     * `Tube_Core\Support\RedisRateLimiter`'s own docblock already
     * documents for tube-core vs tube-cache. Reuses tube-core's
     * `TUBE_CORE_REDIS_HOST`/`TUBE_CORE_REDIS_PORT` constants (the same
     * Redis server, a distinct key prefix) rather than inventing a
     * second set of connection constants.
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
     * The shared session service every auth entry point (register,
     * login, Google OAuth) logs a member in through, so "auto-login
     * after registration" and "Google login" and "email/password login"
     * all end up at exactly one code path that sets the real WordPress
     * auth cookie.
     */
    public function auth_session(): AuthSessionService
    {
        if (null === $this->auth_session) {
            $this->auth_session = new AuthSessionService();
        }

        return $this->auth_session;
    }

    /**
     * The avatar upload/read service, shared between the REST controller
     * and any future internal caller.
     */
    public function avatar_service(): AvatarService
    {
        if (null === $this->avatar_service) {
            $this->avatar_service = new AvatarService();
        }

        return $this->avatar_service;
    }

    /**
     * The configured Google OAuth client, or null when Google Login has
     * no Client ID/Secret configured yet (Phase 6: "If credentials are
     * absent: Google button may be hidden or clearly unavailable" — the
     * absence of this client is exactly the signal
     * `HeaderAccountRenderer`/the login modal use to hide the Google
     * button, and the reason `/auth/google/start` and `/auth/google/callback`
     * are only registered at all when this is non-null).
     */
    public function google_oauth_client(): ?GoogleOAuthClient
    {
        if (null !== $this->google_oauth_client) {
            return $this->google_oauth_client;
        }

        $client = GoogleOAuthClient::from_options();

        if (null === $client) {
            return null;
        }

        $this->google_oauth_client = $client;

        return $this->google_oauth_client;
    }
}
