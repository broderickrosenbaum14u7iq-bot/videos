<?php
/**
 * View for HomepageSeoSettings::render_title_field().
 *
 * Included with $value already in scope — see
 * HomepageSeoSettings::render_title_field(). Every local variable this
 * file itself defines is `tube_seo_`-prefixed, per `tube-theme`'s own
 * PrefixAllGlobals convention for top-level template files.
 *
 * @package Tube_Seo
 *
 * @var string $value
 */

declare(strict_types=1);

use Tube_Seo\Admin\HomepageSeoSettings;

[$tube_seo_min, $tube_seo_max] = HomepageSeoSettings::title_recommended_range();

?>
<input
    type="text"
    id="tube_seo_home_title"
    name="<?php echo esc_attr(HomepageSeoSettings::OPTION_TITLE); ?>"
    value="<?php echo esc_attr($value); ?>"
    class="regular-text"
    style="width:480px;"
/>
<p class="description">
    <?php
    $tube_seo_length      = mb_strlen($value);
    $tube_seo_length_text = (string) $tube_seo_length;
    /* translators: 1: current character count (updated live by JS), 2: recommended minimum, 3: recommended maximum. */
    $tube_seo_guidance = esc_html__(
        '%1$s characters. Recommended: approximately %2$d-%3$d characters. Not enforced.',
        'tube-seo'
    );
    printf(
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html__()'d immediately above; %1$s's own value is esc_html()'d separately below.
        $tube_seo_guidance,
        '<span id="tube_seo_home_title_count">' . esc_html($tube_seo_length_text) . '</span>',
        (int) $tube_seo_min,
        (int) $tube_seo_max
    );
    ?>
</p>
