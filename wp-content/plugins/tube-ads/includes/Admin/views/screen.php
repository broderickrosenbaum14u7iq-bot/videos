<?php
/**
 * View for SettingsScreen::render() — page chrome + tab nav + form wrapper.
 *
 * Included with $tube_ads_settings/$tube_ads_option/$tube_ads_page_slug/
 * $tube_ads_active_tab/$tube_ads_tab_labels already in scope — see
 * SettingsScreen::render(). Every local variable this file (and the tab
 * partials it includes) defines is `tube_ads_`-prefixed, per
 * `tube-theme`'s own PrefixAllGlobals convention for top-level template files.
 *
 * IMPORTANT: `register_setting()` replaces the ENTIRE option value with
 * whatever `$_POST[SettingsRepository::OPTION]` contains — it does not
 * merge into the existing stored value. Every tab's fields are
 * therefore always rendered into this one `<form>` (all six
 * `tab-*.php` partials, every time), so submitting from any one tab
 * still POSTs every other tab's current values too; only the *visible*
 * panel changes with `$tube_ads_active_tab`, via a plain `display:none`
 * on the others — no JavaScript required for this, and no risk of a
 * single-tab save silently wiping out every other tab's settings.
 *
 * @package Tube_Ads
 *
 * @var AdSettings            $tube_ads_settings
 * @var string                $tube_ads_option
 * @var string                $tube_ads_page_slug
 * @var string                $tube_ads_active_tab
 * @var array<string, string> $tube_ads_tab_labels
 */

declare(strict_types=1);

use Tube_Ads\Placement\AdSettings;

?>
<div class="wrap">
    <h1><?php esc_html_e('Tube Ads', 'tube-ads'); ?></h1>

    <h2 class="nav-tab-wrapper">
        <?php foreach ($tube_ads_tab_labels as $tube_ads_tab_slug => $tube_ads_tab_label) : ?>
            <?php
            $tube_ads_tab_url   = add_query_arg(
                [
                    'page' => $tube_ads_page_slug,
                    'tab'  => $tube_ads_tab_slug,
                ],
                admin_url('admin.php')
            );
            $tube_ads_tab_class = 'nav-tab' . ($tube_ads_tab_slug === $tube_ads_active_tab ? ' nav-tab-active' : '');
            ?>
            <a class="<?php echo esc_attr($tube_ads_tab_class); ?>" href="<?php echo esc_url($tube_ads_tab_url); ?>">
                <?php echo esc_html($tube_ads_tab_label); ?>
            </a>
        <?php endforeach; ?>
    </h2>

    <form action="options.php" method="post">
        <?php settings_fields($tube_ads_page_slug); ?>

        <?php foreach (array_keys($tube_ads_tab_labels) as $tube_ads_panel_slug) : ?>
            <div<?php echo $tube_ads_panel_slug === $tube_ads_active_tab ? '' : ' style="display:none"'; ?>>
                <?php require __DIR__ . '/tab-' . $tube_ads_panel_slug . '.php'; ?>
            </div>
        <?php endforeach; ?>

        <?php submit_button(); ?>
    </form>
</div>
