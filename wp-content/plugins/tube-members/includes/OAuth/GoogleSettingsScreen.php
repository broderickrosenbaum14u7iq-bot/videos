<?php
/**
 * The wp-admin settings screen for Google Login credentials.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\OAuth;

use Tube_Members\Support\Params;

/**
 * The wp-admin settings screen for Google Login credentials, per Phase 33.
 * Uses WordPress's Settings API (`register_setting()`/`settings_fields()`)
 * for its own CSRF/nonce handling and capability check — the same
 * infrastructure every other wp-admin settings screen in WordPress core
 * relies on.
 *
 * The Client Secret is never re-rendered in the page source once saved
 * — the password field is redisplayed with a fixed mask value, and
 * {@see self::sanitize_secret()} treats a submission of that exact mask
 * (or an empty value) as "leave unchanged" rather than overwriting the
 * real secret with the mask itself.
 */
final class GoogleSettingsScreen
{
    /**
     * The Settings API option group name.
     */
    private const OPTION_GROUP = 'tube_members_google';

    /**
     * Sentinel value the Client Secret field is redisplayed with once a
     * real secret is already saved — never the real secret itself.
     */
    private const SECRET_MASK = '••••••••••••••••';

    /**
     * `admin_menu` callback.
     */
    public function register_menu(): void
    {
        add_options_page(
            __('Tube Members — Google Login', 'tube-members'),
            __('Tube Members Google', 'tube-members'),
            'manage_options',
            'tube-members-google',
            [$this, 'render']
        );
    }

    /**
     * `admin_init` callback.
     */
    public function register_settings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            'tube_members_google_client_id',
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'tube_members_google_client_secret',
            [
                'type'              => 'string',
                'sanitize_callback' => [$this, 'sanitize_secret'],
                'default'           => '',
            ]
        );
    }

    /**
     * Settings API sanitize callback for the Client Secret field.
     *
     * @param mixed $value The raw submitted value.
     */
    public function sanitize_secret($value): string
    {
        $string_value = Params::string($value);
        $value        = trim($string_value);

        if ('' === $value || self::SECRET_MASK === $value) {
            return Params::string(get_option('tube_members_google_client_secret', ''));
        }

        return $value;
    }

    /**
     * Renders the settings screen.
     */
    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $client_id  = Params::string(get_option('tube_members_google_client_id', ''));
        $has_secret = '' !== Params::string(get_option('tube_members_google_client_secret', ''));
        $enabled    = '' !== $client_id && $has_secret;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Tube Members — Đăng nhập Google', 'tube-members'); ?></h1>

            <p>
                <?php esc_html_e('Trạng thái:', 'tube-members'); ?>
                <strong>
                    <?php
                    echo $enabled
                        ? esc_html__('Đang bật', 'tube-members')
                        : esc_html__('Đang tắt (thiếu Client ID hoặc Client Secret)', 'tube-members');
                    ?>
                </strong>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Redirect URI', 'tube-members'); ?></th>
                    <td>
                        <code><?php echo esc_html(GoogleOAuthClient::redirect_uri()); ?></code>
                        <p class="description">
                            <?php
                            esc_html_e(
                                'Dán URI này vào mục "Authorized redirect URIs" trong Google Cloud Console.',
                                'tube-members'
                            );
                            ?>
                        </p>
                    </td>
                </tr>
            </table>

            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="tube_members_google_client_id">
                                <?php esc_html_e('Client ID', 'tube-members'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="text"
                                class="regular-text"
                                id="tube_members_google_client_id"
                                name="tube_members_google_client_id"
                                value="<?php echo esc_attr($client_id); ?>"
                                autocomplete="off"
                            >
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="tube_members_google_client_secret">
                                <?php esc_html_e('Client Secret', 'tube-members'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="password"
                                class="regular-text"
                                id="tube_members_google_client_secret"
                                name="tube_members_google_client_secret"
                                value="<?php echo $has_secret ? esc_attr(self::SECRET_MASK) : ''; ?>"
                                placeholder="<?php echo esc_attr__('Nhập Client Secret', 'tube-members'); ?>"
                                autocomplete="off"
                            >
                            <p class="description">
                                <?php
                                esc_html_e('Để trống hoặc giữ nguyên nếu không muốn thay đổi.', 'tube-members');
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Lưu cài đặt', 'tube-members')); ?>
            </form>
        </div>
        <?php
    }
}
