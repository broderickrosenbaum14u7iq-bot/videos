<?php
/**
 * Template Name: Latest
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

$tube_theme_videos = tube_search_recently_added(48);

?>

<h1><?php the_title(); ?></h1>

<?php if ([] === $tube_theme_videos) : ?>
    <p><?php esc_html_e('No videos yet.', 'tube-theme'); ?></p>
<?php else : ?>
    <?php tube_theme_prime_video_grid($tube_theme_videos); ?>
    <div class="video-grid">
        <?php foreach ($tube_theme_videos as $tube_theme_video) : ?>
            <?php get_template_part('template-parts/video-card', null, ['video' => $tube_theme_video]); ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
get_footer();
