<?php
/**
 * View for HomepageSeoSettings::render().
 *
 * Included with $tube_seo_page_slug/$tube_seo_option_group already in
 * scope — see HomepageSeoSettings::render(). Every local variable this
 * file itself defines is `tube_seo_`-prefixed, per `tube-theme`'s own
 * PrefixAllGlobals convention for top-level template files.
 *
 * @package Tube_Seo
 *
 * @var string $tube_seo_page_slug
 * @var string $tube_seo_option_group
 */

declare(strict_types=1);

?>
<div class="wrap">
    <h1><?php esc_html_e('Homepage SEO', 'tube-seo'); ?></h1>

    <p>
        <?php esc_html_e('Sets the SEO title/description used for the homepage only.', 'tube-seo'); ?>
        <br />
        <?php esc_html_e('Leave a field empty to keep the existing site title/tagline behavior.', 'tube-seo'); ?>
    </p>

    <form action="options.php" method="post">
        <?php
        settings_fields($tube_seo_option_group);
        do_settings_sections($tube_seo_page_slug);
        submit_button();
        ?>
    </form>
</div>
<script>
(function () {
    function wireCounter(fieldId, counterId) {
        var field = document.getElementById(fieldId);
        var counter = document.getElementById(counterId);

        if (!field || !counter) {
            return;
        }

        var update = function () {
            counter.textContent = String(field.value.length);
        };

        field.addEventListener('input', update);
        update();
    }

    wireCounter('tube_seo_home_title', 'tube_seo_home_title_count');
    wireCounter('tube_seo_home_description', 'tube_seo_home_description_count');
})();
</script>
