<?php
/**
 * The theme header: opens <html>/<head>/<body>, renders SEO tags and the
 * site nav (Phase 13: mega menu + mobile off-canvas nav; §5's SEO
 * call site and its position — before wp_head(), inside <head> — is
 * unchanged from Phase 8).
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

$tube_theme_nav_links = array_filter(
    [
        [
            'label' => __('Trending', 'tube-theme'),
            'url'   => $tube_theme_trending_url,
        ],
        [
            'label' => __('Most Viewed', 'tube-theme'),
            'url'   => $tube_theme_most_viewed_url,
        ],
        [
            'label' => __('Latest', 'tube-theme'),
            'url'   => $tube_theme_latest_url,
        ],
        [
            'label' => __('Actors', 'tube-theme'),
            'url'   => $tube_theme_actors_url,
        ],
        [
            'label' => __('Studios', 'tube-theme'),
            'url'   => $tube_theme_studios_url,
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
        <a class="site-header__home" href="<?php echo esc_url(home_url('/')); ?>">
            <?php bloginfo('name'); ?><span>.</span>
        </a>

        <nav class="site-nav" aria-label="<?php echo esc_attr__('Primary', 'tube-theme'); ?>">
            <div class="site-nav__item">
                <button type="button" class="site-nav__link" aria-haspopup="true" aria-expanded="false">
                    <?php esc_html_e('Categories', 'tube-theme'); ?>
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
                    value="<?php echo esc_attr(tube_search_current_query()); ?>"
                    placeholder="<?php echo esc_attr__('Search videos&hellip;', 'tube-theme'); ?>"
                >
                <button type="submit" aria-label="<?php echo esc_attr__('Search', 'tube-theme'); ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="11" cy="11" r="7" />
                        <line x1="21" y1="21" x2="16.65" y2="16.65" />
                    </svg>
                </button>
            </div>
        </form>

        <button
            type="button"
            class="mobile-nav-toggle"
            data-tube-mobile-nav-open
            aria-haspopup="true"
            aria-expanded="false"
            aria-label="<?php echo esc_attr__('Open menu', 'tube-theme'); ?>"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" fill="none" />
            </svg>
        </button>
    </div>
</header>

<div class="mobile-nav" data-tube-mobile-nav>
    <div class="mobile-nav__header">
        <a class="site-header__home" href="<?php echo esc_url(home_url('/')); ?>">
            <?php bloginfo('name'); ?><span>.</span>
        </a>
        <button
            type="button"
            class="mobile-nav__close"
            data-tube-mobile-nav-close
            aria-label="<?php echo esc_attr__('Close menu', 'tube-theme'); ?>"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" fill="none" />
            </svg>
        </button>
    </div>

    <form class="site-header__search" data-tube-search-base="<?php echo esc_url(home_url('/search/')); ?>">
        <div class="site-header__search-field">
            <input
                type="text"
                name="q"
                value="<?php echo esc_attr(tube_search_current_query()); ?>"
                placeholder="<?php echo esc_attr__('Search videos&hellip;', 'tube-theme'); ?>"
            >
            <button type="submit" aria-label="<?php echo esc_attr__('Search', 'tube-theme'); ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="11" cy="11" r="7" />
                    <line x1="21" y1="21" x2="16.65" y2="16.65" />
                </svg>
            </button>
        </div>
    </form>

    <div class="mobile-nav__section">
        <a class="mobile-nav__link" href="<?php echo esc_url(home_url('/')); ?>">
            <?php esc_html_e('Home', 'tube-theme'); ?>
        </a>
        <?php foreach ($tube_theme_nav_links as $tube_theme_link) : ?>
            <a class="mobile-nav__link" href="<?php echo esc_url($tube_theme_link['url']); ?>">
                <?php echo esc_html($tube_theme_link['label']); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ([] !== $tube_theme_nav_categories) : ?>
        <div class="mobile-nav__section">
            <h2 class="mobile-nav__section-heading"><?php esc_html_e('Categories', 'tube-theme'); ?></h2>
            <?php foreach ($tube_theme_nav_categories as $tube_theme_mobile_category) : ?>
                <?php
                $tube_theme_mobile_term_link = get_term_link($tube_theme_mobile_category);
                $tube_theme_mobile_term_url  = is_string($tube_theme_mobile_term_link)
                    ? $tube_theme_mobile_term_link
                    : '';
                ?>
                <a class="mobile-nav__link" href="<?php echo esc_url($tube_theme_mobile_term_url); ?>">
                    <?php echo esc_html($tube_theme_mobile_category->name); ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<main class="site-main">
