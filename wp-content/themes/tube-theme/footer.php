<?php
/**
 * The theme footer: closes <main>, renders the site footer, closes
 * </body>/</html>. wp_footer()'s call site is unchanged from Phase 8.
 *
 * 2026-08-27: footer content is now admin-editable via Appearance →
 * Customize → Footer ({@see inc/customizer.php}); link COLUMNS are real
 * WordPress Navigation Menus (locations `footer_menu_1..3`). Each
 * column falls back to this theme's own pre-existing real links
 * (never fabricated placeholders) whenever no menu has been assigned
 * yet to its location, so the footer never goes blank on upgrade.
 *
 * 2026-08-28: columns 2/3 additionally accept free-form rich-text
 * content (`tube_theme_footer_column_2_content` / `_3_content`
 * theme_mods, `wp_kses()`-sanitized against an explicit allow-list —
 * {@see tube_theme_footer_content_allowed_html()}). Precedence per
 * column is content > assigned menu > fallback links; column 1 has no
 * content field and keeps its existing menu/fallback-only behavior.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$tube_theme_footer_show_brand       = (bool) get_theme_mod('tube_theme_footer_show_brand', true);
$tube_theme_footer_show_description = (bool) get_theme_mod('tube_theme_footer_show_description', true);
$tube_theme_footer_show_menus       = (bool) get_theme_mod('tube_theme_footer_show_menus', true);
$tube_theme_footer_show_social      = (bool) get_theme_mod('tube_theme_footer_show_social', true);
$tube_theme_footer_show_copyright   = (bool) get_theme_mod('tube_theme_footer_show_copyright', true);

$tube_theme_footer_logo       = get_theme_mod('tube_theme_footer_logo', '');
$tube_theme_footer_logo       = is_string($tube_theme_footer_logo) ? $tube_theme_footer_logo : '';
$tube_theme_footer_brand_text = get_theme_mod('tube_theme_footer_brand_text', get_bloginfo('name'));
$tube_theme_footer_brand_text = is_string($tube_theme_footer_brand_text) && '' !== $tube_theme_footer_brand_text
    ? $tube_theme_footer_brand_text
    : get_bloginfo('name');

$tube_theme_footer_description = get_theme_mod('tube_theme_footer_description', '');
$tube_theme_footer_description = is_string($tube_theme_footer_description) ? trim($tube_theme_footer_description) : '';

$tube_theme_footer_column_default_titles = [
    1 => __('Danh mục', 'tube-theme'),
    2 => __('Khám phá', 'tube-theme'),
    3 => __('Thông tin', 'tube-theme'),
];

$tube_theme_footer_column_titles = [];

foreach ($tube_theme_footer_column_default_titles as $tube_theme_col_num => $tube_theme_col_default) {
    $tube_theme_col_value = get_theme_mod('tube_theme_footer_column_' . $tube_theme_col_num . '_title', $tube_theme_col_default);

    $tube_theme_footer_column_titles[ $tube_theme_col_num ] = is_string($tube_theme_col_value) && '' !== $tube_theme_col_value
        ? $tube_theme_col_value
        : $tube_theme_col_default;
}

// Custom rich-text content per column (2026-08-28, Part B) — only
// columns 2/3 have this field in the Customizer; column 1 always
// resolves to '' here so its own existing category-fallback/menu
// behavior is completely untouched. Precedence (content > menu >
// fallback) is applied per-column in the render loop below.
$tube_theme_footer_column_contents = [1 => ''];

foreach ([2, 3] as $tube_theme_content_col_num) {
    $tube_theme_content_raw = get_theme_mod('tube_theme_footer_column_' . $tube_theme_content_col_num . '_content', '');

    $tube_theme_footer_column_contents[ $tube_theme_content_col_num ] = is_string($tube_theme_content_raw)
        ? trim($tube_theme_content_raw)
        : '';
}

// Backward-compatible fallback link sets — only rendered for a column
// whose menu location has no assigned menu yet. These are the exact
// same real links the old hardcoded footer already showed (categories,
// then Discover-style links, then profile links); nothing here is a
// fabricated placeholder.
$tube_theme_footer_fallback_categories = get_terms(
    [
        'taxonomy'   => 'video_category',
        'hide_empty' => true,
        'number'     => 6,
        'orderby'    => 'name',
    ]
);
$tube_theme_footer_fallback_categories = is_array($tube_theme_footer_fallback_categories) ? $tube_theme_footer_fallback_categories : [];

$tube_theme_footer_fallback_discover = array_filter(
    [
        [
            'label' => __('Video Mới', 'tube-theme'),
            'url'   => tube_theme_page_template_url('page-templates/latest.php'),
        ],
        [
            'label' => __('Thịnh Hành', 'tube-theme'),
            'url'   => tube_theme_page_template_url('page-templates/trending.php'),
        ],
        [
            'label' => __('Xem Nhiều', 'tube-theme'),
            'url'   => tube_theme_page_template_url('page-templates/most-viewed.php'),
        ],
        [
            'label' => __('Tags', 'tube-theme'),
            'url'   => tube_theme_page_template_url('page-templates/tags.php'),
        ],
    ],
    static fn (array $tube_theme_link): bool => null !== $tube_theme_link['url']
);

$tube_theme_footer_fallback_info = array_filter(
    [
        [
            'label' => __('Diễn Viên', 'tube-theme'),
            'url'   => tube_theme_page_template_url('page-templates/actors.php'),
        ],
        [
            'label' => __('Hãng Phim', 'tube-theme'),
            'url'   => tube_theme_page_template_url('page-templates/studios.php'),
        ],
    ],
    static fn (array $tube_theme_link): bool => null !== $tube_theme_link['url']
);

$tube_theme_footer_fallback_by_column = [
    1 => $tube_theme_footer_fallback_categories,
    2 => $tube_theme_footer_fallback_discover,
    3 => $tube_theme_footer_fallback_info,
];

$tube_theme_footer_socials = array_filter(
    [
        'facebook' => get_theme_mod('tube_theme_footer_social_facebook', ''),
        'telegram' => get_theme_mod('tube_theme_footer_social_telegram', ''),
        'twitter'  => get_theme_mod('tube_theme_footer_social_twitter', ''),
        'youtube'  => get_theme_mod('tube_theme_footer_social_youtube', ''),
    ],
    static fn ($tube_theme_url): bool => is_string($tube_theme_url) && '' !== $tube_theme_url
);
?></main>
<footer class="site-footer">
    <div class="site-footer__inner">
        <?php
        $tube_theme_footer_has_social     = $tube_theme_footer_show_social && [] !== $tube_theme_footer_socials;
        $tube_theme_footer_show_brand_col = $tube_theme_footer_show_brand
            || $tube_theme_footer_show_description
            || $tube_theme_footer_has_social;
        ?>
        <div class="site-footer__grid">
            <?php if ($tube_theme_footer_show_brand_col) : ?>
                <div class="site-footer__brand">
                    <?php if ($tube_theme_footer_show_brand) : ?>
                        <a class="site-footer__brand-link" href="<?php echo esc_url(home_url('/')); ?>">
                            <?php if ('' !== $tube_theme_footer_logo) : ?>
                                <img
                                    class="site-footer__logo"
                                    src="<?php echo esc_url($tube_theme_footer_logo); ?>"
                                    alt="<?php echo esc_attr($tube_theme_footer_brand_text); ?>"
                                    loading="lazy"
                                    width="140"
                                    height="36"
                                >
                            <?php else : ?>
                                <?php echo esc_html($tube_theme_footer_brand_text); ?><span>.</span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($tube_theme_footer_show_description && '' !== $tube_theme_footer_description) : ?>
                        <p class="site-footer__description">
                            <?php echo esc_html($tube_theme_footer_description); ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($tube_theme_footer_has_social) : ?>
                        <div class="site-footer__social">
                            <?php foreach ($tube_theme_footer_socials as $tube_theme_social_key => $tube_theme_social_url) : ?>
                                <a
                                    class="site-footer__social-link"
                                    href="<?php echo esc_url($tube_theme_social_url); ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    aria-label="<?php echo esc_attr(ucfirst($tube_theme_social_key)); ?>"
                                >
                                    <?php tube_theme_social_icon($tube_theme_social_key); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php
            if ($tube_theme_footer_show_menus) :
                foreach ([1, 2, 3] as $tube_theme_column_number) :
                    $tube_theme_location       = 'footer_menu_' . $tube_theme_column_number;
                    $tube_theme_has_menu       = has_nav_menu($tube_theme_location);
                    $tube_theme_fallback_set   = $tube_theme_footer_fallback_by_column[ $tube_theme_column_number ];
                    $tube_theme_column_content = $tube_theme_footer_column_contents[ $tube_theme_column_number ];
                    $tube_theme_has_content    = '' !== $tube_theme_column_content;

                    if (!$tube_theme_has_content && !$tube_theme_has_menu && [] === $tube_theme_fallback_set) {
                        continue;
                    }

                    $tube_theme_column_title = $tube_theme_footer_column_titles[ $tube_theme_column_number ];
                    ?>
                    <nav
                        class="site-footer__column"
                        aria-label="<?php echo esc_attr($tube_theme_column_title); ?>"
                    >
                        <h2 class="site-footer__heading">
                            <?php echo esc_html($tube_theme_column_title); ?>
                        </h2>

                        <?php if ($tube_theme_has_content) : ?>
                            <?php
                            // Precedence (Part B4): non-empty custom content always
                            // wins over both the menu and the fallback links for
                            // this column -- re-run through the same explicit
                            // allow-list at render time, not just at save time, so
                            // the output is safe even if a theme_mod were ever set
                            // by something other than this Customizer control.
                            ?>
                            <div class="site-footer__custom-content">
                                <?php
                                echo wp_kses(
                                    $tube_theme_column_content,
                                    tube_theme_footer_content_allowed_html()
                                );
                                ?>
                            </div>
                        <?php elseif ($tube_theme_has_menu) : ?>
                            <?php
                            wp_nav_menu(
                                [
                                    'theme_location' => $tube_theme_location,
                                    'items_wrap'     => '<ul class="site-footer__list">%3$s</ul>',
                                    'fallback_cb'    => false,
                                    'depth'          => 1,
                                ]
                            );
                            ?>
                        <?php else : ?>
                            <ul class="site-footer__list">
                                <?php
                                foreach ($tube_theme_fallback_set as $tube_theme_fallback_item) :
                                    if ($tube_theme_fallback_item instanceof WP_Term) {
                                        $tube_theme_fb_link  = get_term_link($tube_theme_fallback_item);
                                        $tube_theme_fb_url   = is_string($tube_theme_fb_link) ? $tube_theme_fb_link : '';
                                        $tube_theme_fb_label = $tube_theme_fallback_item->name;
                                    } else {
                                        $tube_theme_fb_url   = $tube_theme_fallback_item['url'];
                                        $tube_theme_fb_label = $tube_theme_fallback_item['label'];
                                    }
                                    ?>
                                    <li>
                                        <a href="<?php echo esc_url($tube_theme_fb_url); ?>">
                                            <?php echo esc_html($tube_theme_fb_label); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </nav>
                    <?php
                endforeach;
            endif;
            ?>
        </div>

        <?php if ($tube_theme_footer_show_copyright) : ?>
            <div class="site-footer__bottom">
                <p><?php echo esc_html(tube_theme_footer_copyright_text()); ?></p>
            </div>
        <?php endif; ?>
    </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
