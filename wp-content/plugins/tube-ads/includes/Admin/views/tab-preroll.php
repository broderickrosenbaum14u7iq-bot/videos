<?php
/**
 * "Pre-roll" tab — see screen.php for why every tab's fields render every time.
 *
 * @package Tube_Ads
 *
 * @var AdSettings $tube_ads_settings
 */

declare(strict_types=1);

use Tube_Ads\Placement\AdSettings;
use Tube_Ads\Admin\SettingsRepository;
use Tube_Ads\Placement\PrerollFrequency;

$tube_ads_preroll = $tube_ads_settings->preroll;
$tube_ads_name    = static fn (string $path): string => SettingsRepository::OPTION . '[preroll]' . $path;

?>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e('Enable Pre-roll', 'tube-ads'); ?></th>
        <td>
            <label>
                <input
                    type="checkbox"
                    name="<?php echo esc_attr($tube_ads_name('[enabled]')); ?>"
                    value="1"
                    <?php checked($tube_ads_preroll->enabled); ?>
                />
                <?php esc_html_e('Show a VAST video ad before the main video plays.', 'tube-ads'); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row">
            <label for="tube_ads_vast_url"><?php esc_html_e('VAST Tag URL', 'tube-ads'); ?></label>
        </th>
        <td>
            <input
                type="url"
                id="tube_ads_vast_url"
                name="<?php echo esc_attr($tube_ads_name('[vast_url]')); ?>"
                value="<?php echo esc_attr($tube_ads_preroll->vast_url); ?>"
                class="regular-text"
                placeholder="https://ads.example.com/vast.xml"
            />
            <p class="description">
                <?php
                esc_html_e(
                    'Any provider\'s standard VAST 3/4 tag URL — this system is provider-neutral.',
                    'tube-ads'
                );
                ?>
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row">
            <label for="tube_ads_advertiser_url"><?php esc_html_e('Advertiser URL', 'tube-ads'); ?></label>
        </th>
        <td>
            <input
                type="text"
                inputmode="url"
                id="tube_ads_advertiser_url"
                name="<?php echo esc_attr($tube_ads_name('[advertiser_url]')); ?>"
                value="<?php echo esc_attr($tube_ads_preroll->advertiser_url); ?>"
                class="regular-text"
                placeholder="https://www.milo.com.vn/"
            />
            <p class="description">
                <?php
                esc_html_e(
                    'URL trang nhà quảng cáo sẽ mở trong tab mới khi người xem nhấp vào video quảng cáo.',
                    'tube-ads'
                );
                ?>
            </p>
            <p class="description">
                <?php esc_html_e('Always takes precedence over the ad\'s own VAST ClickThrough.', 'tube-ads'); ?>
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Skip Button', 'tube-ads'); ?></th>
        <td>
            <label>
                <input
                    type="checkbox"
                    name="<?php echo esc_attr($tube_ads_name('[skip_enabled]')); ?>"
                    value="1"
                    <?php checked($tube_ads_preroll->skip_enabled); ?>
                />
                <?php esc_html_e('Allow viewers to skip the ad.', 'tube-ads'); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row">
            <label for="tube_ads_skip_after"><?php esc_html_e('Skip After (seconds)', 'tube-ads'); ?></label>
        </th>
        <td>
            <input
                type="number"
                id="tube_ads_skip_after"
                name="<?php echo esc_attr($tube_ads_name('[skip_after_seconds]')); ?>"
                value="<?php echo esc_attr(strval($tube_ads_preroll->skip_after_seconds)); ?>"
                min="0"
                max="300"
                class="small-text"
            />
            <p class="description">
                <?php esc_html_e('Nhập 0 để bắt buộc người xem xem hết quảng cáo.', 'tube-ads'); ?>
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row">
            <label for="tube_ads_max_duration"><?php esc_html_e('Maximum Ad Duration (seconds)', 'tube-ads'); ?></label>
        </th>
        <td>
            <input
                type="number"
                id="tube_ads_max_duration"
                name="<?php echo esc_attr($tube_ads_name('[max_duration_seconds]')); ?>"
                value="<?php echo esc_attr(strval($tube_ads_preroll->max_duration_seconds)); ?>"
                min="1"
                max="600"
                class="small-text"
            />
            <p class="description">
                <?php esc_html_e('A hard safeguard, independent of the ad\'s own reported duration.', 'tube-ads'); ?>
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row">
            <label for="tube_ads_timeout"><?php esc_html_e('Ad Timeout (seconds)', 'tube-ads'); ?></label>
        </th>
        <td>
            <input
                type="number"
                id="tube_ads_timeout"
                name="<?php echo esc_attr($tube_ads_name('[timeout_seconds]')); ?>"
                value="<?php echo esc_attr(strval($tube_ads_preroll->timeout_seconds)); ?>"
                min="1"
                max="60"
                class="small-text"
            />
            <p class="description">
                <?php
                esc_html_e(
                    'How long to wait for the VAST request/parse before giving up and playing the real video.',
                    'tube-ads'
                );
                ?>
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Device Targeting', 'tube-ads'); ?></th>
        <td>
            <label style="margin-right: 1.5em;">
                <input
                    type="checkbox"
                    name="<?php echo esc_attr($tube_ads_name('[desktop_enabled]')); ?>"
                    value="1"
                    <?php checked($tube_ads_preroll->desktop_enabled); ?>
                />
                <?php esc_html_e('Desktop', 'tube-ads'); ?>
            </label>
            <label>
                <input
                    type="checkbox"
                    name="<?php echo esc_attr($tube_ads_name('[mobile_enabled]')); ?>"
                    value="1"
                    <?php checked($tube_ads_preroll->mobile_enabled); ?>
                />
                <?php esc_html_e('Mobile', 'tube-ads'); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Frequency Cap', 'tube-ads'); ?></th>
        <td>
            <select name="<?php echo esc_attr($tube_ads_name('[frequency]')); ?>">
                <?php foreach (PrerollFrequency::cases() as $tube_ads_frequency_case) : ?>
                    <option
                        value="<?php echo esc_attr($tube_ads_frequency_case->value); ?>"
                        <?php selected($tube_ads_preroll->frequency === $tube_ads_frequency_case); ?>
                    >
                        <?php echo esc_html($tube_ads_frequency_case->label()); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span style="margin-left: 1em;">
                <label for="tube_ads_frequency_minutes"><?php esc_html_e('Minutes (if applicable):', 'tube-ads'); ?></label>
                <input
                    type="number"
                    id="tube_ads_frequency_minutes"
                    name="<?php echo esc_attr($tube_ads_name('[frequency_minutes]')); ?>"
                    value="<?php echo esc_attr(strval($tube_ads_preroll->frequency_minutes)); ?>"
                    min="1"
                    max="1440"
                    class="small-text"
                />
            </span>
            <p class="description">
                <?php
                esc_html_e(
                    'Enforced client-side (session storage). If storage is unavailable, pre-roll is simply shown every play — it never blocks playback.',
                    'tube-ads'
                );
                ?>
            </p>
        </td>
    </tr>
</table>
