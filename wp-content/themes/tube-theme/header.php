<?php
/**
 * The theme header: opens <html>/<head>/<body>, renders SEO tags and the
 * site nav (§5's SEO call site and its position — before wp_head(),
 * inside <head> — is unchanged from Phase 8).
 *
 * 2026-08-28: mobile now reuses this exact same markup — `.site-header__
 * search` and `.site-nav` (mega-menu included) — instead of the old
 * separate icon-triggered `.mobile-search-overlay`/off-canvas `.mobile-
 * nav` panels (both removed). `tube-theme.css`'s own `@media (max-width:
 * 1023px)` rules wrap `.site-nav` onto its own row below brand/search/
 * account via `flex-wrap` + `order`, not a second copy of this markup —
 * see that block's own comment for why. `$tube_theme_nav_categories` is
 * still fetched once here and passed only to the mega-menu, the same as
 * before.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$tube_theme_trending_url    = tube_theme_page_template_url('page-templates/trending.php');
$tube_theme_most_viewed_url = tube_theme_page_template_url('page-templates/most-viewed.php');
$tube_theme_latest_url      = tube_theme_page_template_url('page-templates/latest.php');
$tube_theme_actors_url      = tube_theme_page_template_url('page-templates/actors.php');
$tube_theme_studios_url     = tube_theme_page_template_url('page-templates/studios.php');
$tube_theme_tags_url        = tube_theme_page_template_url('page-templates/tags.php');

$tube_theme_nav_links = array_filter(
    [
        [
            'label' => __('Video Mới', 'tube-theme'),
            'url'   => $tube_theme_latest_url,
        ],
        [
            'label' => __('Thịnh Hành', 'tube-theme'),
            'url'   => $tube_theme_trending_url,
        ],
        [
            'label' => __('Xem Nhiều', 'tube-theme'),
            'url'   => $tube_theme_most_viewed_url,
        ],
        [
            'label' => __('Diễn Viên', 'tube-theme'),
            'url'   => $tube_theme_actors_url,
        ],
        [
            'label' => __('Hãng Phim', 'tube-theme'),
            'url'   => $tube_theme_studios_url,
        ],
        [
            'label' => __('Tags', 'tube-theme'),
            'url'   => $tube_theme_tags_url,
        ],
    ],
    static fn (array $tube_theme_link): bool => null !== $tube_theme_link['url']
);

// Fetched once and reused for both the desktop mega-menu and the mobile
// nav panel below, rather than two separate get_terms() calls for the
// same conceptual data with two different limits.
$tube_theme_nav_categories = get_terms(
    [
        'taxonomy'   => 'video_category',
        'hide_empty' => true,
        'number'     => 12,
        'orderby'    => 'name',
    ]
);
$tube_theme_nav_categories = is_array($tube_theme_nav_categories) ? $tube_theme_nav_categories : [];

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php tube_seo_head(); ?>
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<header class="site-header">
    <div class="site-header__inner">
        <?php tube_theme_render_site_brand(); ?>

        <nav class="site-nav" aria-label="<?php echo esc_attr__('Primary', 'tube-theme'); ?>">
            <div class="site-nav__item">
                <button type="button" class="site-nav__link" aria-haspopup="true" aria-expanded="false">
                    <?php esc_html_e('Danh Mục', 'tube-theme'); ?>
                    <svg class="site-nav__caret" viewBox="0 0 12 12" aria-hidden="true">
                        <path d="M2 4l4 4 4-4" stroke="currentColor" fill="none" stroke-width="1.5" />
                    </svg>
                </button>
                <?php
                get_template_part(
                    'template-parts/mega-menu',
                    null,
                    ['categories' => $tube_theme_nav_categories]
                );
                ?>
            </div>
            <?php foreach ($tube_theme_nav_links as $tube_theme_link) : ?>
                <div class="site-nav__item">
                    <a class="site-nav__link" href="<?php echo esc_url($tube_theme_link['url']); ?>">
                        <?php echo esc_html($tube_theme_link['label']); ?>
                    </a>
                </div>
            <?php endforeach; ?>
        </nav>

        <form class="site-header__search" data-tube-search-base="<?php echo esc_url(home_url('/search/')); ?>">
            <div class="site-header__search-field">
                <input
                    type="text"
                    name="q"
                    value="<?php echo esc_attr(tube_search_current_query_display()); ?>"
                    placeholder="<?php echo esc_attr__('Tìm kiếm video&hellip;', 'tube-theme'); ?>"
                >
                <button type="submit" aria-label="<?php echo esc_attr__('Tìm kiếm', 'tube-theme'); ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="11" cy="11" r="7" />
                        <line x1="21" y1="21" x2="16.65" y2="16.65" />
                    </svg>
                </button>
            </div>
        </form>

        <div class="site-header__account" data-tube-header-account>
            <?php do_action('tube_members_render_header_account'); ?>
        </div>
    </div>
</header>

<main class="site-main">
