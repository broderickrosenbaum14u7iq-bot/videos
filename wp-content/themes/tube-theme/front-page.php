<?php
/**
 * Homepage: Trending, Most Viewed, and Recently Added rows.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$tube_theme_sections = [
    'trending'       => [
        'label'  => __('Trending', 'tube-theme'),
        'videos' => tube_search_trending(12),
    ],
    'most_viewed'    => [
        'label'  => __('Most Viewed', 'tube-theme'),
        'videos' => tube_search_most_viewed(12),
    ],
    'recently_added' => [
        'label'  => __('Recently Added', 'tube-theme'),
        'videos' => tube_search_recently_added(12),
    ],
];

foreach ($tube_theme_sections as $tube_theme_section) :
    if ([] === $tube_theme_section['videos']) {
        continue;
    }
    ?>
    <h2 class="section-heading"><?php echo esc_html($tube_theme_section['label']); ?></h2>
    <?php tube_theme_prime_video_grid($tube_theme_section['videos']); ?>
    <div class="video-grid">
        <?php foreach ($tube_theme_section['videos'] as $tube_theme_video) : ?>
            <?php get_template_part('template-parts/video-card', null, ['video' => $tube_theme_video]); ?>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>

<?php
get_footer();
