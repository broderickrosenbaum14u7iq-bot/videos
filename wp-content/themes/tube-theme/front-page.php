<?php
/**
 * Homepage: hero, Trending, Most Viewed, Recently Added, Categories.
 *
 * Phase 13: none of these rows are infinite-scroll (decision #4 —
 * tube_search_trending()/_most_viewed()/_recently_added() take only a
 * fixed $limit, no page/offset, and the homepage itself has no
 * /page/{n}/ entry in the frozen URL table, ARCHITECTURE.md §15.1). Each
 * row instead links out to its own dedicated listing page (Trending/
 * Most-Viewed/Latest — Phase 8 Page templates) where the full catalog is
 * browsable, when such a page has been created.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

get_header();

// tube_search_trending(12) is called once and reused for both the hero
// (item 0) and the Trending row -- calling trending(1) separately for
// the hero would be a second, redundant cached-query call for a subset
// already in hand.
$tube_theme_trending_videos = tube_search_trending(12);

get_template_part('template-parts/hero', null, ['video' => $tube_theme_trending_videos[0] ?? null]);

$tube_theme_has_any_content = [] !== $tube_theme_trending_videos;

$tube_theme_sections = [
    'trending'       => [
        'label'    => __('Trending', 'tube-theme'),
        'videos'   => $tube_theme_trending_videos,
        'view_all' => tube_theme_page_template_url('page-templates/trending.php'),
    ],
    'most_viewed'    => [
        'label'    => __('Most Viewed', 'tube-theme'),
        'videos'   => tube_search_most_viewed(12),
        'view_all' => tube_theme_page_template_url('page-templates/most-viewed.php'),
    ],
    'recently_added' => [
        'label'    => __('Recently Added', 'tube-theme'),
        'videos'   => tube_search_recently_added(12),
        'view_all' => tube_theme_page_template_url('page-templates/latest.php'),
    ],
];

foreach ($tube_theme_sections as $tube_theme_section) :
    if ([] === $tube_theme_section['videos']) {
        continue;
    }

    $tube_theme_has_any_content = true;
    ?>
    <div class="section">
        <div class="section-heading-row">
            <h2 class="section-heading"><?php echo esc_html($tube_theme_section['label']); ?></h2>
            <?php if (null !== $tube_theme_section['view_all']) : ?>
                <a class="section-view-all" href="<?php echo esc_url($tube_theme_section['view_all']); ?>">
                    <?php esc_html_e('View all', 'tube-theme'); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php get_template_part('template-parts/video-grid', null, ['videos' => $tube_theme_section['videos']]); ?>
    </div>
<?php endforeach; ?>

<?php
$tube_theme_categories = get_terms(
    [
        'taxonomy'   => 'video_category',
        'hide_empty' => true,
        'number'     => 12,
        'orderby'    => 'name',
    ]
);
$tube_theme_categories = is_array($tube_theme_categories) ? $tube_theme_categories : [];

if ([] !== $tube_theme_categories) {
    $tube_theme_has_any_content = true;
}
?>
<?php if (!$tube_theme_has_any_content) : ?>
    <div class="empty-state">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M4 5h16v14H4z" />
            <path d="M10 9l6 3-6 3z" fill="currentColor" stroke="none" />
        </svg>
        <p><?php esc_html_e('No videos have been published yet. Check back soon.', 'tube-theme'); ?></p>
    </div>
<?php endif; ?>

<?php if ([] !== $tube_theme_categories) : ?>
    <div class="section">
        <div class="section-heading-row">
            <h2 class="section-heading"><?php esc_html_e('Categories', 'tube-theme'); ?></h2>
        </div>
        <div class="category-tiles">
            <?php foreach ($tube_theme_categories as $tube_theme_category) : ?>
                <?php
                $tube_theme_term_link = get_term_link($tube_theme_category);
                $tube_theme_term_url  = is_string($tube_theme_term_link) ? $tube_theme_term_link : '';
                ?>
                <a class="category-tile" href="<?php echo esc_url($tube_theme_term_url); ?>">
                    <?php echo esc_html($tube_theme_category->name); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php
get_footer();
