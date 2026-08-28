<?php
/**
 * Frontend account page (`/tai-khoan/`), owned entirely by tube-members.
 *
 * Selected via `Tube_Members\Routing\AccountRouting::route_template()`'s
 * `template_include` filter — never `locate_template()`-d from the
 * theme, so every line of markup/data here lives in this plugin and
 * disappears cleanly if it's deactivated (Phase 41: "do not put business
 * logic into the theme"). Calls the theme's `get_header()`/`get_footer()`
 * and reuses its existing `template-parts/video-grid` component for the
 * "Video đã lưu" section — the same "plugin owns data + its own markup,
 * theme supplies shared chrome/components" split `single-video.php`'s
 * `tube_comments_render_section()` hook already establishes.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

use Tube_Core\Plugin as Tube_Core_Plugin;
use Tube_Members\Email\EmailVerificationService;
use Tube_Members\Email\VerificationEmailSender;
use Tube_Members\Profile\AvatarService;

if (!defined('ABSPATH')) {
    exit;
}

$tube_members_user = wp_get_current_user();

wp_enqueue_style(
    'tube-members-account',
    plugins_url('../../../assets/css/tube-members-account.css', __FILE__),
    ['tube-members'],
    TUBE_MEMBERS_VERSION
);

wp_enqueue_script(
    'tube-members-account',
    plugins_url('../../../assets/js/tube-members-account.js', __FILE__),
    [],
    TUBE_MEMBERS_VERSION,
    true
);

wp_localize_script(
    'tube-members-account',
    'TubeMembersAccountConfig',
    [
        'meUrl'                 => esc_url_raw(rest_url('tube/v1/members/me')),
        'passwordUrl'           => esc_url_raw(rest_url('tube/v1/members/me/password')),
        'avatarUrl'             => esc_url_raw(rest_url('tube/v1/members/me/avatar')),
        'resendVerificationUrl' => esc_url_raw(rest_url('tube/v1/members/me/resend-verification')),
        'restNonce'             => wp_create_nonce('wp_rest'),
    ]
);

$tube_members_email_verified = (new EmailVerificationService(new VerificationEmailSender()))
    ->is_verified($tube_members_user);

$tube_members_saved_ids  = [];
$tube_members_saved_rows = [];

if (class_exists(Tube_Core_Plugin::class)) {
    $tube_members_saved_ids = Tube_Core_Plugin::instance()->saved_video_repository()->video_ids_for_user(
        $tube_members_user->ID,
        60
    );
}

if ([] !== $tube_members_saved_ids && function_exists('tube_search_get_video')) {
    foreach ($tube_members_saved_ids as $tube_members_saved_id) {
        $tube_members_row = tube_search_get_video($tube_members_saved_id);

        if (null !== $tube_members_row) {
            $tube_members_saved_rows[] = $tube_members_row;
        }
    }
}

get_header();
?>

<div class="tube-account-page">
    <header class="tube-account-page__header">
        <div class="tube-account-page__avatar-wrap">
            <img
                class="tube-account-page__avatar"
                src="<?php echo esc_url((new AvatarService())->url_for($tube_members_user->ID)); ?>"
                alt=""
                width="88"
                height="88"
                data-tube-account-avatar-preview
            >
            <label class="tube-account-page__avatar-btn">
                <?php esc_html_e('Đổi ảnh đại diện', 'tube-members'); ?>
                <input type="file" accept="image/jpeg,image/png,image/webp" data-tube-account-avatar-input hidden>
            </label>
        </div>
        <div class="tube-account-page__identity">
            <h1 class="tube-account-page__title">👤 <?php esc_html_e('Hồ sơ', 'tube-members'); ?></h1>

            <form class="tube-account-page__field-form" data-tube-account-name-form>
                <label>
                    <?php esc_html_e('Tên hiển thị', 'tube-members'); ?>
                    <input
                        type="text"
                        name="display_name"
                        value="<?php echo esc_attr($tube_members_user->display_name); ?>"
                        required
                    >
                </label>
                <button type="submit"><?php esc_html_e('Lưu', 'tube-members'); ?></button>
                <span class="tube-account-page__saved-hint" data-tube-account-name-saved hidden>
                    <?php esc_html_e('Đã lưu', 'tube-members'); ?>
                </span>
            </form>

            <p class="tube-account-page__email">
                <?php esc_html_e('Email', 'tube-members'); ?>: <?php echo esc_html($tube_members_user->user_email); ?>
            </p>

            <?php if ($tube_members_email_verified) : ?>
                <p class="tube-account-page__email-status tube-account-page__email-status--verified">
                    ✅ <?php esc_html_e('Đã xác thực', 'tube-members'); ?>
                </p>
            <?php else : ?>
                <p class="tube-account-page__email-status tube-account-page__email-status--unverified">
                    ⚠ <?php esc_html_e('Chưa xác thực', 'tube-members'); ?>
                    <button type="button" class="tube-account-page__link-btn" data-tube-account-resend-verification>
                        <?php esc_html_e('Gửi lại email xác thực', 'tube-members'); ?>
                    </button>
                </p>
                <p class="tube-account-page__hint" data-tube-account-resend-hint hidden></p>
            <?php endif; ?>

            <button type="button" class="tube-account-page__link-btn" data-tube-account-password-toggle>
                <?php esc_html_e('Đổi mật khẩu', 'tube-members'); ?>
            </button>

            <form class="tube-account-page__password-form" data-tube-account-password-form hidden>
                <p class="tube-account-page__error" data-tube-account-password-error hidden></p>
                <label>
                    <?php esc_html_e('Mật khẩu hiện tại', 'tube-members'); ?>
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>
                <label>
                    <?php esc_html_e('Mật khẩu mới', 'tube-members'); ?>
                    <input type="password" name="new_password" autocomplete="new-password" required>
                </label>
                <label>
                    <?php esc_html_e('Xác nhận mật khẩu mới', 'tube-members'); ?>
                    <input type="password" name="new_password_confirm" autocomplete="new-password" required>
                </label>
                <button type="submit"><?php esc_html_e('Cập nhật mật khẩu', 'tube-members'); ?></button>
            </form>
        </div>
    </header>

    <section id="video-da-luu" class="tube-account-page__section">
        <h2 class="section-heading">🎞️ <?php esc_html_e('Video đã lưu', 'tube-members'); ?></h2>
        <?php if ([] !== $tube_members_saved_rows) : ?>
            <?php get_template_part('template-parts/video-grid', null, ['videos' => $tube_members_saved_rows]); ?>
        <?php else : ?>
            <p class="tube-account-page__empty">
                <?php esc_html_e('Bạn chưa lưu video nào.', 'tube-members'); ?>
            </p>
        <?php endif; ?>
    </section>

    <section id="binh-luan-cua-toi" class="tube-account-page__section">
        <h2 class="section-heading">💬 <?php esc_html_e('Bình luận của tôi', 'tube-members'); ?></h2>
        <?php if (function_exists('tube_comments_render_my_comments_mount')) : ?>
            <?php tube_comments_render_my_comments_mount(); ?>
        <?php else : ?>
            <p class="tube-account-page__empty">
                <?php esc_html_e('Chưa có bình luận nào.', 'tube-members'); ?>
            </p>
        <?php endif; ?>
    </section>
</div>

<?php
get_footer();
