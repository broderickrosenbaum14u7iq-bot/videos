<?php
/**
 * Renders the header account slot and the login/register modal.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Render;

use Tube_Members\Email\EmailVerificationService;
use Tube_Members\Email\VerificationEmailSender;
use Tube_Members\OAuth\GoogleOAuthClient;
use Tube_Members\Profile\AvatarService;
use Tube_Members\Routing\AccountRouting;
use Tube_Members\Support\Nonces;
use Tube_Members\Support\Params;

/**
 * Renders `header.php`'s `.site-header__account` slot (`do_action(
 * 'tube_members_render_header_account')` — see `Tube_Members\Plugin::boot()`)
 * and the login/register modal (`wp_footer`), per Phases 4/7.
 *
 * Both the header slot and the modal are rendered on every page load
 * (never lazy-fetched), so the header correctly reflects logged-in
 * state on first paint with no flash of the logged-out state — the
 * modal is simply hidden (`hidden` attribute) until a trigger opens it.
 */
final class HeaderAccountRenderer
{
    /**
     * `tube_members_render_header_account` callback: the header slot —
     * either a "Đăng nhập" trigger or the avatar/name account menu.
     */
    public function render(): void
    {
        $user = wp_get_current_user();

        if (0 === $user->ID) {
            ?>
            <button type="button" class="tube-account__login-btn" data-tube-auth-open="login">
                <?php esc_html_e('Đăng nhập', 'tube-members'); ?>
            </button>
            <?php
            return;
        }

        $avatar_service  = new AvatarService();
        $avatar_url      = $avatar_service->url_for($user->ID);
        $avatar_fallback = $avatar_service->default_avatar_url($user->ID);
        $account_url     = AccountRouting::url();
        ?>
        <div class="tube-account" data-tube-account-menu>
            <button
                type="button"
                class="tube-account__trigger"
                data-tube-account-toggle
                aria-haspopup="true"
                aria-expanded="false"
            >
                <img
                    class="tube-account__avatar"
                    src="<?php echo esc_url($avatar_url); ?>"
                    data-tube-avatar-fallback="<?php echo esc_attr($avatar_fallback); ?>"
                    alt=""
                    width="28"
                    height="28"
                >
                <span class="tube-account__name"><?php echo esc_html($user->display_name); ?></span>
                <svg class="tube-account__caret" viewBox="0 0 12 12" aria-hidden="true">
                    <path d="M2 4l4 4 4-4" stroke="currentColor" fill="none" stroke-width="1.5" />
                </svg>
            </button>
            <div class="tube-account__menu" data-tube-account-dropdown hidden>
                <a class="tube-account__menu-item" href="<?php echo esc_url($account_url); ?>">
                    <?php esc_html_e('Hồ sơ của tôi', 'tube-members'); ?>
                </a>
                <a class="tube-account__menu-item" href="<?php echo esc_url($account_url . '#video-da-luu'); ?>">
                    <?php esc_html_e('Video đã lưu', 'tube-members'); ?>
                </a>
                <a class="tube-account__menu-item" href="<?php echo esc_url($account_url . '#binh-luan-cua-toi'); ?>">
                    <?php esc_html_e('Bình luận của tôi', 'tube-members'); ?>
                </a>
                <button
                    type="button"
                    class="tube-account__menu-item tube-account__menu-item--danger"
                    data-tube-auth-logout
                >
                    <?php esc_html_e('Đăng xuất', 'tube-members'); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * `wp_footer` callback: the login/register modal markup, present on
     * every page (hidden until a `[data-tube-auth-open]` trigger fires),
     * per Phase 4. Guests only — logged-in visitors never see this
     * (their header slot has no trigger that opens it), but the markup
     * itself is rendered unconditionally to keep this method simple and
     * cheap; nothing inside it runs or is reachable without a trigger.
     */
    public function render_login_modal(): void
    {
        $google_client = class_exists(GoogleOAuthClient::class) ? GoogleOAuthClient::from_options() : null;
        $current_url   = $this->current_url();
        $google_href   = null === $google_client
            ? ''
            : rest_url('tube/v1/auth/google/start') . '?return_to=' . rawurlencode($current_url);
        ?>
        <div class="tube-auth-modal" data-tube-auth-modal hidden>
            <div class="tube-auth-modal__backdrop" data-tube-auth-close></div>
            <div class="tube-auth-modal__panel" role="dialog" aria-modal="true" aria-labelledby="tube-auth-modal-title">
                <button
                    type="button"
                    class="tube-auth-modal__close"
                    data-tube-auth-close
                    aria-label="<?php echo esc_attr__('Đóng', 'tube-members'); ?>"
                >×</button>

                <div data-tube-auth-view="login">
                    <h2 id="tube-auth-modal-title" class="tube-auth-modal__title">
                        <?php esc_html_e('Đăng nhập', 'tube-members'); ?>
                    </h2>

                    <?php if ('' !== $google_href) : ?>
                        <a class="tube-auth-modal__google-btn" href="<?php echo esc_url($google_href); ?>">
                            <?php $this->google_icon(); ?>
                            <?php esc_html_e('Tiếp tục với Google', 'tube-members'); ?>
                        </a>
                        <div class="tube-auth-modal__divider">
                            <span><?php esc_html_e('hoặc', 'tube-members'); ?></span>
                        </div>
                    <?php endif; ?>

                    <form data-tube-auth-form="login" novalidate>
                        <p class="tube-auth-modal__error" data-tube-auth-error hidden></p>
                        <label class="tube-auth-modal__field">
                            <?php esc_html_e('Email hoặc tên đăng nhập', 'tube-members'); ?>
                            <input type="text" name="login" autocomplete="username" required>
                        </label>
                        <label class="tube-auth-modal__field">
                            <?php esc_html_e('Mật khẩu', 'tube-members'); ?>
                            <input type="password" name="password" autocomplete="current-password" required>
                        </label>
                        <button type="submit" class="tube-auth-modal__submit">
                            <?php esc_html_e('Đăng nhập', 'tube-members'); ?>
                        </button>
                    </form>

                    <button type="button" class="tube-auth-modal__link" data-tube-auth-switch="forgot-password">
                        <?php esc_html_e('Quên mật khẩu?', 'tube-members'); ?>
                    </button>

                    <p class="tube-auth-modal__switch">
                        <?php esc_html_e('Chưa có tài khoản?', 'tube-members'); ?>
                        <button type="button" data-tube-auth-switch="register">
                            <?php esc_html_e('Đăng ký', 'tube-members'); ?>
                        </button>
                    </p>
                </div>

                <div data-tube-auth-view="forgot-password" hidden>
                    <h2 class="tube-auth-modal__title"><?php esc_html_e('Quên mật khẩu?', 'tube-members'); ?></h2>
                    <p class="tube-auth-modal__hint">
                        <?php
                        esc_html_e(
                            'Nhập email hoặc tên đăng nhập của bạn, chúng tôi sẽ gửi liên kết đặt lại mật khẩu.',
                            'tube-members'
                        );
                        ?>
                    </p>

                    <form data-tube-auth-form="forgot-password" novalidate>
                        <p class="tube-auth-modal__error" data-tube-auth-error hidden></p>
                        <p
                            class="tube-auth-modal__error tube-reset-page__success"
                            data-tube-auth-forgot-success
                            hidden
                        ></p>
                        <label class="tube-auth-modal__field">
                            <?php esc_html_e('Email hoặc tên đăng nhập', 'tube-members'); ?>
                            <input type="text" name="login" autocomplete="username" required>
                        </label>
                        <button type="submit" class="tube-auth-modal__submit">
                            <?php esc_html_e('Gửi liên kết đặt lại', 'tube-members'); ?>
                        </button>
                    </form>

                    <p class="tube-auth-modal__switch">
                        <button type="button" data-tube-auth-switch="login">
                            <?php esc_html_e('Quay lại đăng nhập', 'tube-members'); ?>
                        </button>
                    </p>
                </div>

                <div data-tube-auth-view="register" hidden>
                    <h2 class="tube-auth-modal__title"><?php esc_html_e('Đăng ký', 'tube-members'); ?></h2>

                    <?php if ('' !== $google_href) : ?>
                        <a class="tube-auth-modal__google-btn" href="<?php echo esc_url($google_href); ?>">
                            <?php $this->google_icon(); ?>
                            <?php esc_html_e('Tiếp tục với Google', 'tube-members'); ?>
                        </a>
                        <div class="tube-auth-modal__divider">
                            <span><?php esc_html_e('hoặc', 'tube-members'); ?></span>
                        </div>
                    <?php endif; ?>

                    <form data-tube-auth-form="register" novalidate>
                        <p class="tube-auth-modal__error" data-tube-auth-error hidden></p>
                        <label class="tube-auth-modal__field">
                            <?php esc_html_e('Tên hiển thị', 'tube-members'); ?>
                            <input type="text" name="display_name" autocomplete="name" required>
                        </label>
                        <label class="tube-auth-modal__field">
                            <?php esc_html_e('Email', 'tube-members'); ?>
                            <input type="email" name="email" autocomplete="email" required>
                        </label>
                        <label class="tube-auth-modal__field">
                            <?php esc_html_e('Mật khẩu', 'tube-members'); ?>
                            <input type="password" name="password" autocomplete="new-password" required>
                        </label>
                        <label class="tube-auth-modal__field">
                            <?php esc_html_e('Xác nhận mật khẩu', 'tube-members'); ?>
                            <input type="password" name="password_confirm" autocomplete="new-password" required>
                        </label>
                        <button type="submit" class="tube-auth-modal__submit">
                            <?php esc_html_e('Đăng ký', 'tube-members'); ?>
                        </button>
                    </form>

                    <p class="tube-auth-modal__switch">
                        <?php esc_html_e('Đã có tài khoản?', 'tube-members'); ?>
                        <button type="button" data-tube-auth-switch="login">
                            <?php esc_html_e('Đăng nhập', 'tube-members'); ?>
                        </button>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * A small inline Google "G" mark — no external asset request.
     */
    private function google_icon(): void
    {
        ?>
        <svg class="tube-auth-modal__google-icon" viewBox="0 0 18 18" aria-hidden="true">
            <path
                fill="#4285F4"
                d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 01-1.8 2.72v2.26h2.9c1.7-1.57 2.7-3.87 2.7-6.62z"
            />
            <path
                fill="#34A853"
                d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.9-2.26c-.8.54-1.84.86-3.06.86-2.35 0-4.34-1.59-5.05-3.72H.96v2.33A9 9 0 009 18z"
            />
            <path
                fill="#FBBC05"
                d="M3.95 10.7A5.4 5.4 0 013.68 9c0-.59.1-1.17.27-1.7V4.97H.96A9 9 0 000 9c0 1.45.35 2.83.96 4.03l2.99-2.33z"
            />
            <path
                fill="#EA4335"
                d="M9 3.58c1.32 0 2.51.45 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0A9 9 0 00.96 4.97l2.99 2.33C4.66 5.17 6.65 3.58 9 3.58z"
            />
        </svg>
        <?php
    }

    /**
     * `wp_enqueue_scripts` callback: this plugin's own modal/account-menu
     * script and stylesheet, present on every page (the header slot and
     * modal are both always-rendered), localized with the REST URLs,
     * anonymous auth nonce, and authenticated `wp_rest` nonce the script needs.
     */
    public function enqueue_assets(): void
    {
        wp_enqueue_style(
            'tube-members',
            plugins_url('assets/css/tube-members.css', TUBE_MEMBERS_FILE),
            [],
            self::asset_version('assets/css/tube-members.css')
        );

        wp_enqueue_script(
            'tube-members-auth',
            plugins_url('assets/js/tube-members-auth.js', TUBE_MEMBERS_FILE),
            [],
            self::asset_version('assets/js/tube-members-auth.js'),
            true
        );

        $current_user      = wp_get_current_user();
        $is_email_verified = 0 !== $current_user->ID
            && (new EmailVerificationService(new VerificationEmailSender()))->is_verified($current_user);

        wp_localize_script(
            'tube-members-auth',
            'TubeMembersConfig',
            [
                'loginUrl'              => esc_url_raw(rest_url('tube/v1/auth/login')),
                'registerUrl'           => esc_url_raw(rest_url('tube/v1/auth/register')),
                'forgotPasswordUrl'     => esc_url_raw(rest_url('tube/v1/auth/forgot-password')),
                'resetPasswordUrl'      => esc_url_raw(rest_url('tube/v1/auth/reset-password')),
                'logoutUrl'             => esc_url_raw(rest_url('tube/v1/auth/logout')),
                'meUrl'                 => esc_url_raw(rest_url('tube/v1/members/me')),
                'resendVerificationUrl' => esc_url_raw(rest_url('tube/v1/members/me/resend-verification')),
                'accountUrl'            => esc_url_raw(AccountRouting::url()),
                'authNonce'             => wp_create_nonce(Nonces::AUTH),
                'restNonce'             => wp_create_nonce('wp_rest'),
                'isLoggedIn'            => is_user_logged_in(),
                'isEmailVerified'       => $is_email_verified,
            ]
        );
    }

    /**
     * The cache-busting `$ver` for one of this plugin's own CSS/JS
     * files — the file's own mtime, not the fixed `TUBE_MEMBERS_VERSION`
     * plugin-version constant. Mirrors `Tube_Ads\Plugin::asset_version()`
     * (and `tube_theme_asset_version()` in the theme) — same root cause
     * this project already fixed twice elsewhere: a version string that
     * never changes between edits means a browser that already fetched
     * this stylesheet keeps serving that stale copy for up to nginx's
     * 30-day `Cache-Control: max-age` even after the file on disk
     * changes (2026-08-27 compact-mobile pass — this plugin's header
     * account styles were still on the fixed version when that pass's
     * own CSS edits here didn't show up live).
     *
     * @param string $relative_path Path under this plugin's own directory, e.g. `assets/css/tube-members.css`.
     */
    private static function asset_version(string $relative_path): string
    {
        $path = TUBE_MEMBERS_DIR . '/' . $relative_path;

        if (! file_exists($path)) {
            return TUBE_MEMBERS_VERSION;
        }

        $mtime = filemtime($path);

        return false !== $mtime ? (string) $mtime : TUBE_MEMBERS_VERSION;
    }

    /**
     * The current request's full URL, used as the login modal's Google
     * button `return_to` and the "Quên mật khẩu?" redirect target, so
     * either flow lands the visitor back on the exact page they started
     * from.
     */
    private function current_url(): string
    {
        $host = isset($_SERVER['HTTP_HOST'])
            ? sanitize_text_field(Params::string(wp_unslash($_SERVER['HTTP_HOST'])))
            : '';
        $uri  = isset($_SERVER['REQUEST_URI'])
            ? sanitize_text_field(Params::string(wp_unslash($_SERVER['REQUEST_URI'])))
            : '/';

        if ('' === $host) {
            return home_url('/');
        }

        return (is_ssl() ? 'https://' : 'http://') . $host . $uri;
    }
}
