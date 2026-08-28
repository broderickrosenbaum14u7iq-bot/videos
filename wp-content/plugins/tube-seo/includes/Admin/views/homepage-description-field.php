<?php
/**
 * View for HomepageSeoSettings::render_description_field().
 *
 * Included with $value already in scope — see
 * HomepageSeoSettings::render_description_field(). Every local variable
 * this file itself defines is `tube_seo_`-prefixed, per `tube-theme`'s
 * own PrefixAllGlobals convention for top-level template files.
 *
 * @package Tube_Seo
 *
 * @var string $value
 */

declare(strict_types=1);

use Tube_Seo\Admin\HomepageSeoSettings;

[$tube_seo_min, $tube_seo_max] = HomepageSeoSettings::description_recommended_range();

?>
<textarea
    id="tube_seo_home_description"
    name="<?php echo esc_attr(HomepageSeoSettings::OPTION_DESCRIPTION); ?>"
    rows="3"
    class="large-text"
    style="width:480px;"
><?php echo esc_textarea($value); ?></textarea>
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
        '<span id="tube_seo_home_description_count">' . esc_html($tube_seo_length_text) . '</span>',
        (int) $tube_seo_min,
        (int) $tube_seo_max
    );
    ?>
</p>
