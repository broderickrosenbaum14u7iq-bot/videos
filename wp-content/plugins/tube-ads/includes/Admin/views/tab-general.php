<?php
/**
 * "General" tab — see screen.php for why every tab's fields render every time.
 *
 * @package Tube_Ads
 *
 * @var AdSettings $tube_ads_settings
 */

declare(strict_types=1);

use Tube_Ads\Placement\AdSettings;
use Tube_Ads\Admin\SettingsRepository;

$tube_ads_name = static fn (string $path): string => SettingsRepository::OPTION . $path;

?>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e('Enable Ads', 'tube-ads'); ?></th>
        <td>
            <label>
                <input
                    type="checkbox"
                    name="<?php echo esc_attr($tube_ads_name('[enabled]')); ?>"
                    value="1"
                    <?php checked($tube_ads_settings->enabled); ?>
                />
                <?php esc_html_e('Turn on the advertising system site-wide.', 'tube-ads'); ?>
            </label>
            <p class="description">
                <?php
                esc_html_e(
                    'When off, no ad script/CSS loads and no placement renders anywhere — the site behaves exactly as it does without this plugin.',
                    'tube-ads'
                );
                ?>
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Debug Logging', 'tube-ads'); ?></th>
        <td>
            <label>
                <input
                    type="checkbox"
                    name="<?php echo esc_attr($tube_ads_name('[debug]')); ?>"
                    value="1"
                    <?php checked($tube_ads_settings->debug); ?>
                />
                <?php esc_html_e('Log pre-roll lifecycle events to the browser console.', 'tube-ads'); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Test Mode', 'tube-ads'); ?></th>
        <td>
            <label>
                <input
                    type="checkbox"
                    name="<?php echo esc_attr($tube_ads_name('[test_mode]')); ?>"
                    value="1"
                    <?php checked($tube_ads_settings->test_mode); ?>
                />
                <?php esc_html_e('Use the test VAST URL below instead of the real pre-roll tag.', 'tube-ads'); ?>
            </label>
            <p>
                <label for="tube_ads_test_vast_url"><?php esc_html_e('Test VAST URL', 'tube-ads'); ?></label><br />
                <input
                    type="text"
                    inputmode="url"
                    id="tube_ads_test_vast_url"
                    name="<?php echo esc_attr($tube_ads_name('[test_vast_url]')); ?>"
                    value="<?php echo esc_attr($tube_ads_settings->test_vast_url); ?>"
                    class="regular-text"
                    placeholder="https://... or /wp-content/uploads/..."
                />
            </p>
            <p class="description">
                <?php
                esc_html_e(
                    'For local verification only — this never affects real ad-network reporting/metrics, it only changes which URL the browser requests. Defaults off.',
                    'tube-ads'
                );
                ?>
            </p>
        </td>
    </tr>
</table>
