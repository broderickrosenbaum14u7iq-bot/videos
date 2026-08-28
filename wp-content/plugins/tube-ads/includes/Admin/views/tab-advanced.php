<?php
/**
 * "Advanced" tab (global custom script) — see screen.php for why every
 * tab's fields render every time.
 *
 * @package Tube_Ads
 *
 * @var AdSettings $tube_ads_settings
 */

declare(strict_types=1);

use Tube_Ads\Admin\SettingsRepository;
use Tube_Ads\Placement\AdSettings;

$tube_ads_global_script = $tube_ads_settings->global_script;
$tube_ads_name          = static fn (string $path): string => SettingsRepository::OPTION . '[global_script]' . $path;

?>
<h2><?php esc_html_e('Global Custom Script', 'tube-ads'); ?></h2>
<p class="description">
    <?php
    esc_html_e(
        'A controlled place for network code that legitimately needs to load site-wide (e.g. a popunder/social-bar provider\'s verification script) — not for a positioned ad creative. Never injected while empty.',
        'tube-ads'
    );
    ?>
</p>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e('Enable', 'tube-ads'); ?></th>
        <td>
            <label>
                <input
                    type="checkbox"
                    name="<?php echo esc_attr($tube_ads_name('[enabled]')); ?>"
                    value="1"
                    <?php checked($tube_ads_global_script->enabled); ?>
                />
                <?php esc_html_e('Active', 'tube-ads'); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row">
            <label for="tube_ads_global_script_code"><?php esc_html_e('Code', 'tube-ads'); ?></label>
        </th>
        <td>
            <textarea
                id="tube_ads_global_script_code"
                name="<?php echo esc_attr($tube_ads_name('[code]')); ?>"
                rows="6"
                class="large-text code"
            ><?php echo esc_textarea($tube_ads_global_script->code); ?></textarea>
            <p class="description">
                <?php
                esc_html_e(
                    'Loads on every front-end page when enabled. External provider code can meaningfully affect page performance — use only when a network specifically requires site-wide loading.',
                    'tube-ads'
                );
                ?>
            </p>
        </td>
    </tr>
</table>
