<?php
/**
 * One display placement's shared field set — reused by tab-player.php,
 * tab-homepage.php, and tab-other.php (every `Placement::configurable_display_placements()`
 * entry has this exact same shape).
 *
 * Included with $tube_ads_placement (Placement)/$tube_ads_config
 * (PlacementConfig)/$tube_ads_show_grid_position (bool) already in scope.
 *
 * @package Tube_Ads
 *
 * @var Placement       $tube_ads_placement
 * @var PlacementConfig $tube_ads_config
 * @var bool            $tube_ads_show_grid_position
 */

declare(strict_types=1);

use Tube_Ads\Admin\SettingsRepository;
use Tube_Ads\Placement\AdType;
use Tube_Ads\Placement\Placement;
use Tube_Ads\Placement\PlacementConfig;

$tube_ads_name = static fn (string $path): string => sprintf(
    '%s[placements][%s]%s',
    SettingsRepository::OPTION,
    $tube_ads_placement->value,
    $path
);

?>
<h2><?php echo esc_html($tube_ads_placement->label()); ?></h2>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php esc_html_e('Enable', 'tube-ads'); ?></th>
        <td>
            <label>
                <input
                    type="checkbox"
                    name="<?php echo esc_attr($tube_ads_name('[enabled]')); ?>"
                    value="1"
                    <?php checked($tube_ads_config->enabled); ?>
                />
                <?php esc_html_e('Active', 'tube-ads'); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Type', 'tube-ads'); ?></th>
        <td>
            <label style="margin-right: 1.5em;">
                <input
                    type="radio"
                    name="<?php echo esc_attr($tube_ads_name('[type]')); ?>"
                    value="<?php echo esc_attr(AdType::CustomHtml->value); ?>"
                    <?php checked(AdType::CustomHtml === $tube_ads_config->type); ?>
                />
                <?php esc_html_e('Custom HTML/JS', 'tube-ads'); ?>
            </label>
            <label>
                <input
                    type="radio"
                    name="<?php echo esc_attr($tube_ads_name('[type]')); ?>"
                    value="<?php echo esc_attr(AdType::ImageBanner->value); ?>"
                    <?php checked(AdType::ImageBanner === $tube_ads_config->type); ?>
                />
                <?php esc_html_e('Image Banner (direct-sold)', 'tube-ads'); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row">
            <label for="tube_ads_html_<?php echo esc_attr($tube_ads_placement->value); ?>">
                <?php esc_html_e('Custom HTML/JS Code', 'tube-ads'); ?>
            </label>
        </th>
        <td>
            <textarea
                id="tube_ads_html_<?php echo esc_attr($tube_ads_placement->value); ?>"
                name="<?php echo esc_attr($tube_ads_name('[html]')); ?>"
                rows="5"
                class="large-text code"
            ><?php echo esc_textarea($tube_ads_config->html); ?></textarea>
            <p class="description">
                <?php
                esc_html_e(
                    'Used only when Type is Custom HTML/JS. External provider code can impact page performance — test after saving.',
                    'tube-ads'
                );
                ?>
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php esc_html_e('Image Banner', 'tube-ads'); ?></th>
        <td>
            <p>
                <label for="tube_ads_image_url_<?php echo esc_attr($tube_ads_placement->value); ?>">
                    <?php esc_html_e('Image URL', 'tube-ads'); ?>
                </label><br />
                <input
                    type="url"
                    id="tube_ads_image_url_<?php echo esc_attr($tube_ads_placement->value); ?>"
                    name="<?php echo esc_attr($tube_ads_name('[image_url]')); ?>"
                    value="<?php echo esc_attr($tube_ads_config->image_url); ?>"
                    class="regular-text"
                />
            </p>
            <p>
                <label for="tube_ads_link_url_<?php echo esc_attr($tube_ads_placement->value); ?>">
                    <?php esc_html_e('Destination URL', 'tube-ads'); ?>
                </label><br />
                <input
                    type="url"
                    id="tube_ads_link_url_<?php echo esc_attr($tube_ads_placement->value); ?>"
                    name="<?php echo esc_attr($tube_ads_name('[link_url]')); ?>"
                    value="<?php echo esc_attr($tube_ads_config->link_url); ?>"
                    class="regular-text"
                />
            </p>
            <p>
                <label for="tube_ads_alt_<?php echo esc_attr($tube_ads_placement->value); ?>">
                    <?php esc_html_e('Alt Text', 'tube-ads'); ?>
                </label><br />
                <input
                    type="text"
                    id="tube_ads_alt_<?php echo esc_attr($tube_ads_placement->value); ?>"
                    name="<?php echo esc_attr($tube_ads_name('[alt_text]')); ?>"
                    value="<?php echo esc_attr($tube_ads_config->alt_text); ?>"
                    class="regular-text"
                />
            </p>
            <p>
                <label>
                    <input
                        type="checkbox"
                        name="<?php echo esc_attr($tube_ads_name('[open_in_new_tab]')); ?>"
                        value="1"
                        <?php checked($tube_ads_config->open_in_new_tab); ?>
                    />
                    <?php esc_html_e('Open link in a new tab', 'tube-ads'); ?>
                </label>
            </p>
            <p>
                <label for="tube_ads_starts_<?php echo esc_attr($tube_ads_placement->value); ?>">
                    <?php esc_html_e('Start Date (optional)', 'tube-ads'); ?>
                </label>
                <input
                    type="date"
                    id="tube_ads_starts_<?php echo esc_attr($tube_ads_placement->value); ?>"
                    name="<?php echo esc_attr($tube_ads_name('[starts_at]')); ?>"
                    value="<?php echo esc_attr($tube_ads_config->starts_at?->format('Y-m-d') ?? ''); ?>"
                />
                <label for="tube_ads_ends_<?php echo esc_attr($tube_ads_placement->value); ?>" style="margin-left: 1em;">
                    <?php esc_html_e('End Date (optional)', 'tube-ads'); ?>
                </label>
                <input
                    type="date"
                    id="tube_ads_ends_<?php echo esc_attr($tube_ads_placement->value); ?>"
                    name="<?php echo esc_attr($tube_ads_name('[ends_at]')); ?>"
                    value="<?php echo esc_attr($tube_ads_config->ends_at?->format('Y-m-d') ?? ''); ?>"
                />
            </p>
        </td>
    </tr>
    <?php if ($tube_ads_show_grid_position) : ?>
        <tr>
            <th scope="row">
                <label for="tube_ads_position_<?php echo esc_attr($tube_ads_placement->value); ?>">
                    <?php esc_html_e('Position (after card #)', 'tube-ads'); ?>
                </label>
            </th>
            <td>
                <input
                    type="number"
                    id="tube_ads_position_<?php echo esc_attr($tube_ads_placement->value); ?>"
                    name="<?php echo esc_attr($tube_ads_name('[grid_position]')); ?>"
                    value="<?php echo esc_attr(strval($tube_ads_config->grid_position)); ?>"
                    min="1"
                    max="100"
                    class="small-text"
                />
            </td>
        </tr>
    <?php endif; ?>
    <tr>
        <th scope="row"><?php esc_html_e('Device Targeting', 'tube-ads'); ?></th>
        <td>
            <label style="margin-right: 1.5em;">
                <input
                    type="checkbox"
                    name="<?php echo esc_attr($tube_ads_name('[desktop_enabled]')); ?>"
                    value="1"
                    <?php checked($tube_ads_config->desktop_enabled); ?>
                />
                <?php esc_html_e('Desktop', 'tube-ads'); ?>
            </label>
            <label>
                <input
                    type="checkbox"
                    name="<?php echo esc_attr($tube_ads_name('[mobile_enabled]')); ?>"
                    value="1"
                    <?php checked($tube_ads_config->mobile_enabled); ?>
                />
                <?php esc_html_e('Mobile', 'tube-ads'); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row">
            <label for="tube_ads_label_<?php echo esc_attr($tube_ads_placement->value); ?>">
                <?php esc_html_e('Label (internal note)', 'tube-ads'); ?>
            </label>
        </th>
        <td>
            <input
                type="text"
                id="tube_ads_label_<?php echo esc_attr($tube_ads_placement->value); ?>"
                name="<?php echo esc_attr($tube_ads_name('[label]')); ?>"
                value="<?php echo esc_attr($tube_ads_config->label); ?>"
                class="regular-text"
            />
            <p class="description"><?php esc_html_e('Never shown to visitors — for your own reference.', 'tube-ads'); ?></p>
        </td>
    </tr>
</table>
<hr />
