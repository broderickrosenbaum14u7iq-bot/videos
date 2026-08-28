<?php
/**
 * Password-reset landing page (`/dat-lai-mat-khau/`), owned entirely by tube-members.
 *
 * Selected via `Tube_Members\Routing\PasswordResetRouting::route_template()`'s
 * `template_include` filter. Unlike the email-verification result page,
 * this one has real client-side logic of its own (a form submit calling
 * `POST /tube/v1/auth/reset-password`, handled by
 * `assets/js/tube-members-auth.js`'s own `[data-tube-reset-form]` block)
 * -- `login`/`key` are read here only to embed as hidden fields, never
 * validated in PHP on page load (see the routing class's own docblock
 * for why: validation happens only when the form actually submits).
 *
 * @package Tube_Members
 */

declare(strict_types=1);

use Tube_Members\Support\Nonces;
use Tube_Members\Support\Params;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a reset link's login/key pair IS the authorization; there is no prior form submission to carry a nonce from, the same posture EmailVerificationRouting's own identical comment documents for its own uid/token pair.
$tube_members_login = Params::string(wp_unslash($_GET['login'] ?? ''));
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
$tube_members_key = Params::string(wp_unslash($_GET['key'] ?? ''));

get_header();
?>

<div class="tube-reset-page">
    <div class="tube-reset-page__card">
        <h1 class="tube-auth-modal__title"><?php esc_html_e('Đặt lại mật khẩu', 'tube-members'); ?></h1>

        <?php if ('' === $tube_members_login || '' === $tube_members_key) : ?>
            <p class="tube-verify-page__message">
                <?php esc_html_e('Liên kết đặt lại mật khẩu không hợp lệ.', 'tube-members'); ?>
            </p>
            <a class="tube-verify-page__action" href="<?php echo esc_url(home_url('/')); ?>">
                <?php esc_html_e('Về trang chủ', 'tube-members'); ?>
            </a>
        <?php else : ?>
            <form data-tube-reset-form novalidate>
                <p class="tube-auth-modal__error" data-tube-reset-error hidden></p>
                <p class="tube-auth-modal__error tube-reset-page__success" data-tube-reset-success hidden></p>

                <input type="hidden" name="login" value="<?php echo esc_attr($tube_members_login); ?>">
                <input type="hidden" name="key" value="<?php echo esc_attr($tube_members_key); ?>">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(Nonces::AUTH)); ?>">

                <label class="tube-auth-modal__field">
                    <?php esc_html_e('Mật khẩu mới', 'tube-members'); ?>
                    <input type="password" name="new_password" autocomplete="new-password" required>
                </label>
                <label class="tube-auth-modal__field">
                    <?php esc_html_e('Xác nhận mật khẩu mới', 'tube-members'); ?>
                    <input type="password" name="new_password_confirm" autocomplete="new-password" required>
                </label>
                <button type="submit" class="tube-auth-modal__submit">
                    <?php esc_html_e('Đặt lại mật khẩu', 'tube-members'); ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php
get_footer();
