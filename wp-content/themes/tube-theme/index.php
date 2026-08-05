<?php
/**
 * The final fallback template WordPress's own template hierarchy resolves
 * to when nothing more specific matches (front-page.php, single-video.php,
 * the taxonomy/archive/search templates, or a Page template all take
 * precedence). Every page type this project actually serves has its own
 * dedicated template, per ARCHITECTURE.md §12 Phase 8, so this is reached
 * only in cases outside this project's own content model.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

get_header();

if (have_posts()) {
    while (have_posts()) {
        the_post();
        ?>
        <article>
            <h1><?php the_title(); ?></h1>
            <div><?php the_content(); ?></div>
        </article>
        <?php
    }
} else {
    ?>
    <p><?php esc_html_e('Nothing found.', 'tube-theme'); ?></p>
    <?php
}

get_footer();
