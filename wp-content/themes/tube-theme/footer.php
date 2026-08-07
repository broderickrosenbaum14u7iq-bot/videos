<?php
/**
 * The theme footer: closes <main>, renders the site footer, closes
 * </body>/</html>. Phase 13: expanded to a multi-column footer;
 * wp_footer()'s call site is unchanged from Phase 8.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$tube_theme_year = gmdate('Y');

$tube_theme_footer_categories = get_terms(
    [
        'taxonomy'   => 'video_category',
        'hide_empty' => true,
        'number'     => 6,
        'orderby'    => 'name',
    ]
);
$tube_theme_footer_categories = is_array($tube_theme_footer_categories) ? $tube_theme_footer_categories : [];

$tube_theme_footer_links = array_filter(
    [
        [
            'label' => __('Trending', 'tube-theme'),
            'url'   => tube_theme_page_template_url('page-templates/trending.php'),
        ],
        [
            'label' => __('Most Viewed', 'tube-theme'),
            'url'   => tube_theme_page_template_url('page-templates/most-viewed.php'),
        ],
        [
            'label' => __('Latest', 'tube-theme'),
            'url'   => tube_theme_page_template_url('page-templates/latest.php'),
        ],
    ],
    static fn (array $tube_theme_link): bool => null !== $tube_theme_link['url']
);

$tube_theme_footer_profile_links = array_filter(
    [
        [
            'label' => __('Actors', 'tube-theme'),
            'url'   => tube_theme_page_template_url('page-templates/actors.php'),
        ],
        [
            'label' => __('Studios', 'tube-theme'),
            'url'   => tube_theme_page_template_url('page-templates/studios.php'),
        ],
    ],
    static fn (array $tube_theme_link): bool => null !== $tube_theme_link['url']
);
?></main>
<footer class="site-footer">
    <div class="site-footer__inner">
        <div class="site-footer__grid">
            <div>
                <a class="site-header__home" href="<?php echo esc_url(home_url('/')); ?>">
                    <?php bloginfo('name'); ?><span>.</span>
                </a>
            </div>

            <?php if ([] !== $tube_theme_footer_links) : ?>
                <div>
                    <h2 class="site-footer__heading"><?php esc_html_e('Discover', 'tube-theme'); ?></h2>
                    <div class="site-footer__list">
                        <?php foreach ($tube_theme_footer_links as $tube_theme_link) : ?>
                            <a href="<?php echo esc_url($tube_theme_link['url']); ?>">
                                <?php echo esc_html($tube_theme_link['label']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ([] !== $tube_theme_footer_categories) : ?>
                <div>
                    <h2 class="site-footer__heading"><?php esc_html_e('Categories', 'tube-theme'); ?></h2>
                    <div class="site-footer__list">
                        <?php foreach ($tube_theme_footer_categories as $tube_theme_category) : ?>
                            <?php
                            $tube_theme_term_link = get_term_link($tube_theme_category);
                            $tube_theme_term_url  = is_string($tube_theme_term_link) ? $tube_theme_term_link : '';
                            ?>
                            <a href="<?php echo esc_url($tube_theme_term_url); ?>">
                                <?php echo esc_html($tube_theme_category->name); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ([] !== $tube_theme_footer_profile_links) : ?>
                <div>
                    <h2 class="site-footer__heading"><?php esc_html_e('Browse', 'tube-theme'); ?></h2>
                    <div class="site-footer__list">
                        <?php foreach ($tube_theme_footer_profile_links as $tube_theme_link) : ?>
                            <a href="<?php echo esc_url($tube_theme_link['url']); ?>">
                                <?php echo esc_html($tube_theme_link['label']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="site-footer__bottom">
            <p>&copy; <?php echo esc_html($tube_theme_year); ?> <?php bloginfo('name'); ?></p>
        </div>
    </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
