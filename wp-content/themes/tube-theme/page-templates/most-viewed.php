<?php
/**
 * Template Name: Most Viewed
 *
 * Same shape as page-templates/trending.php — see its docblock for why
 * this is a Page template rather than a new rewrite rule.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$tube_theme_videos = tube_search_most_viewed(48);

?>

<h1><?php the_title(); ?></h1>

<?php
get_template_part(
    'template-parts/video-grid',
    null,
    [
        'videos'        => $tube_theme_videos,
        'empty_message' => __('Chưa có video nào.', 'tube-theme'),
    ]
);
?>

<?php
get_footer();
