<?php
/**
 * Template Name: Trending
 *
 * Assignable to any WordPress Page — there is no dedicated URL for this
 * listing in ARCHITECTURE.md §15.1's frozen URL table, so, per this
 * phase's "respect the frozen URL architecture, no URL redesign"
 * instruction, it's exposed as an ordinary WordPress Page (whatever
 * slug an editor gives it), not a new custom rewrite rule.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$tube_theme_videos = tube_search_trending(48);

?>

<h1><?php the_title(); ?></h1>

<?php if ([] === $tube_theme_videos) : ?>
    <p><?php esc_html_e('No trending videos yet.', 'tube-theme'); ?></p>
<?php else : ?>
    <div class="video-grid">
        <?php foreach ($tube_theme_videos as $tube_theme_video) : ?>
            <?php get_template_part('template-parts/video-card', null, ['video' => $tube_theme_video]); ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
get_footer();
