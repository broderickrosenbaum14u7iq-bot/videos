<?php
/**
 * The theme header: opens <html>/<head>/<body>, renders SEO tags and the site nav.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

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
    <a class="site-header__home" href="<?php echo esc_url(home_url('/')); ?>"><?php bloginfo('name'); ?></a>
    <form class="site-header__search" data-tube-search-base="<?php echo esc_url(home_url('/search/')); ?>">
        <input
            type="text"
            name="q"
            value="<?php echo esc_attr(tube_search_current_query()); ?>"
            placeholder="<?php echo esc_attr__('Search videos&hellip;', 'tube-theme'); ?>"
        >
        <button type="submit"><?php echo esc_html__('Search', 'tube-theme'); ?></button>
    </form>
</header>
<main class="site-main">
