<?php
/**
 * Email-verification result page (`/xac-thuc-email/`), owned entirely by tube-members.
 *
 * Selected via `Tube_Members\Routing\EmailVerificationRouting::route_template()`'s
 * `template_include` filter, which has already run the actual
 * verification and handed its result in via `$tube_members_verification_result`
 * — this file is pure presentation, no logic of its own (2026-08-27
 * email-verification task).
 *
 * @package Tube_Members
 */

declare(strict_types=1);

use Tube_Members\Email\VerificationResult;
use Tube_Members\Routing\AccountRouting;

if (!defined('ABSPATH')) {
    exit;
}

$tube_members_verification_result = get_query_var('tube_members_verification_result');

$tube_members_messages = [
    VerificationResult::Verified->value        => ['✅', 'Email đã được xác thực thành công.'],
    VerificationResult::AlreadyVerified->value => ['✅', 'Email của bạn đã được xác thực.'],
    VerificationResult::ExpiredToken->value    => [
        '⚠',
        'Liên kết xác thực đã hết hạn. Vui lòng yêu cầu email xác thực mới.',
    ],
    VerificationResult::InvalidToken->value    => ['⚠', 'Liên kết xác thực không hợp lệ.'],
    VerificationResult::UserNotFound->value    => ['⚠', 'Liên kết xác thực không hợp lệ.'],
];

$tube_members_key     = $tube_members_verification_result instanceof VerificationResult
    ? $tube_members_verification_result->value
    : VerificationResult::InvalidToken->value;
$tube_members_icon    = $tube_members_messages[ $tube_members_key ][0];
$tube_members_message = $tube_members_messages[ $tube_members_key ][1];
$tube_members_success = in_array(
    $tube_members_key,
    [VerificationResult::Verified->value, VerificationResult::AlreadyVerified->value],
    true
);

get_header();
?>

<div class="tube-verify-page">
    <div class="tube-verify-page__card">
        <p class="tube-verify-page__icon" aria-hidden="true"><?php echo esc_html($tube_members_icon); ?></p>
        <p class="tube-verify-page__message"><?php echo esc_html($tube_members_message); ?></p>

        <?php if ($tube_members_success) : ?>
            <?php if (is_user_logged_in()) : ?>
                <a class="tube-verify-page__action" href="<?php echo esc_url(home_url('/')); ?>">
                    <?php esc_html_e('Quay lại xem video', 'tube-members'); ?>
                </a>
            <?php else : ?>
                <p class="tube-verify-page__hint">
                    <?php esc_html_e('Bạn có thể đăng nhập ngay bây giờ.', 'tube-members'); ?>
                </p>
                <a class="tube-verify-page__action" href="<?php echo esc_url(home_url('/')); ?>">
                    <?php esc_html_e('Đăng nhập', 'tube-members'); ?>
                </a>
            <?php endif; ?>
        <?php else : ?>
            <a class="tube-verify-page__action" href="<?php echo esc_url(AccountRouting::url()); ?>">
                <?php esc_html_e('Đến trang tài khoản', 'tube-members'); ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php
get_footer();
