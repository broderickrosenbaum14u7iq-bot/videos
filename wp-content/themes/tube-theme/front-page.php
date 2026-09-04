<?php
/**
 * Homepage: discovery chips, hero, Trending + Popular Tags, Recently
 * Added, Most Viewed, Categories.
 *
 * Phase 14 ("Phim Tối Cổ" redesign): reordered to match the target
 * information hierarchy (Trending directly under the hero, Recently
 * Added next, Most Viewed after that, Categories last) and given a
 * discovery-chip strip + a real "Tags Phổ Biến" block — every data
 * source here (`tube_search_trending()`/`_most_viewed()`/
 * `_recently_added()`, {@see tube_theme_popular_tags()}) already existed
 * and was already cached before this phase; only the template
 * arrangement and copy changed.
 *
 * None of these rows are infinite-scroll (decision #4 —
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
$tube_theme_most_viewed     = tube_search_most_viewed(12);
$tube_theme_recently_added  = tube_search_recently_added(12);

$tube_theme_categories = get_terms(
    [
        'taxonomy'   => 'video_category',
        'hide_empty' => true,
        'number'     => 12,
        'orderby'    => 'name',
    ]
);
$tube_theme_categories = is_array($tube_theme_categories) ? $tube_theme_categories : [];

$tube_theme_popular_tags = tube_theme_popular_tags(20);

$tube_theme_trending_url    = tube_theme_page_template_url('page-templates/trending.php');
$tube_theme_most_viewed_url = tube_theme_page_template_url('page-templates/most-viewed.php');
$tube_theme_latest_url      = tube_theme_page_template_url('page-templates/latest.php');
$tube_theme_all_tags_url    = tube_theme_page_template_url('page-templates/tags.php');

// Discovery chips: the fixed "Video Mới / Thịnh Hành / Xem Nhiều" trio
// (each linking to its real dedicated page when an editor has assigned
// one, otherwise falling back to this same homepage's own matching
// section — never a fabricated destination), then real category
// shortcuts, then real popular-tag shortcuts. "Đánh Giá Cao" is
// deliberately omitted — this project has no rating system to back it.
// Each primary chip gets its own `type` (not one shared "primary") so
// each can carry its own distinct two-tone gradient (2026-08-28 chip
// polish) rather than all three sharing one color.
// Site-brand navigation wording (docs/DEPLOY_NEW_SITE.md multi-site
// identity layer) -- only the label text differs per brand; the
// destination and every other chip stay identical for every site.
$tube_theme_primary_labels = tube_theme_primary_chip_labels();

$tube_theme_chips = [
    [
        'label' => '🆕 ' . $tube_theme_primary_labels['new'],
        'url'   => $tube_theme_latest_url ?? home_url('/#latest'),
        'type'  => 'primary-new',
    ],
    [
        'label' => '🔥 ' . $tube_theme_primary_labels['trending'],
        'url'   => $tube_theme_trending_url ?? home_url('/#trending'),
        'type'  => 'primary-trending',
    ],
    [
        'label' => '👀 ' . $tube_theme_primary_labels['popular'],
        'url'   => $tube_theme_most_viewed_url ?? home_url('/#most-viewed'),
        'type'  => 'primary-popular',
    ],
];

foreach ($tube_theme_categories as $tube_theme_chip_category) {
    $tube_theme_chip_link = get_term_link($tube_theme_chip_category);

    if (is_string($tube_theme_chip_link)) {
        $tube_theme_chip_meta = tube_theme_discovery_category_meta($tube_theme_chip_category->slug);

        $tube_theme_chips[] = [
            'label'       => $tube_theme_chip_meta['emoji'] . ' ' . $tube_theme_chip_category->name,
            'url'         => $tube_theme_chip_link,
            'type'        => 'category',
            'color_class' => $tube_theme_chip_meta['color_class']
                ?? tube_theme_discovery_category_color_class($tube_theme_chip_category->term_id),
        ];
    }
}

foreach (array_slice($tube_theme_popular_tags, 0, 8) as $tube_theme_chip_tag) {
    $tube_theme_chip_link = get_term_link($tube_theme_chip_tag);

    if (is_string($tube_theme_chip_link)) {
        $tube_theme_chips[] = [
            'label'       => '#' . $tube_theme_chip_tag->name,
            'url'         => $tube_theme_chip_link,
            'type'        => 'tag',
            'color_class' => tube_theme_tag_color_class($tube_theme_chip_tag->term_id),
        ];
    }
}

get_template_part('template-parts/discovery-chips', null, ['chips' => $tube_theme_chips]);

get_template_part(
    'template-parts/hero',
    null,
    [
        'video'    => $tube_theme_trending_videos[0] ?? null,
        // Only consumed by the dongtoico brand's own featured+trending
        // hero composition (template-parts/hero.php); every other brand
        // ignores this key entirely.
        'trending' => array_slice($tube_theme_trending_videos, 1, 4),
    ]
);

$tube_theme_has_any_content = [] !== $tube_theme_trending_videos
    || [] !== $tube_theme_most_viewed
    || [] !== $tube_theme_recently_added
    || [] !== $tube_theme_categories;

?>

<?php if ([] !== $tube_theme_trending_videos) : ?>
    <div class="section section--trending" id="trending">
        <div class="trending-with-tags">
            <div class="trending-with-tags__main">
                <div class="section-heading-row">
                    <h2 class="section-heading"><?php esc_html_e('Thịnh Hành', 'tube-theme'); ?></h2>
                    <?php if (null !== $tube_theme_trending_url) : ?>
                        <a class="section-view-all" href="<?php echo esc_url($tube_theme_trending_url); ?>">
                            <?php esc_html_e('Xem tất cả', 'tube-theme'); ?>
                        </a>
                    <?php endif; ?>
                </div>
                <?php
                get_template_part(
                    'template-parts/video-grid',
                    null,
                    ['videos' => array_slice($tube_theme_trending_videos, 0, 10)]
                );
                ?>
            </div>
            <?php if ([] !== $tube_theme_popular_tags) : ?>
                <div class="trending-with-tags__aside">
                    <?php
                    get_template_part(
                        'template-parts/popular-tags',
                        null,
                        [
                            'tags'         => $tube_theme_popular_tags,
                            'view_all_url' => $tube_theme_all_tags_url,
                        ]
                    );
                    ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php
// clipphotvn's own "editorial mosaic" section (brief's own "ONE special
// mid-homepage section") -- built from Most Viewed data specifically so
// it doesn't just repeat the same rows the hero/Trending section above
// already used from Trending. Only this one brand ever calls it;
// mosaic.php itself renders nothing for every other brand's page
// request in practice since front-page.php simply never calls it then.
if ('clipphotvn' === tube_theme_site_brand()) {
    get_template_part('template-parts/mosaic', null, ['videos' => $tube_theme_most_viewed]);
}
?>

<?php if ([] !== $tube_theme_recently_added) : ?>
    <div class="section section--recent" id="latest">
        <div class="section-heading-row">
            <h2 class="section-heading"><?php esc_html_e('Mới Cập Nhật', 'tube-theme'); ?></h2>
            <?php if (null !== $tube_theme_latest_url) : ?>
                <a class="section-view-all" href="<?php echo esc_url($tube_theme_latest_url); ?>">
                    <?php esc_html_e('Xem tất cả', 'tube-theme'); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php get_template_part('template-parts/video-grid', null, ['videos' => $tube_theme_recently_added]); ?>
    </div>
<?php endif; ?>

<?php if ([] !== $tube_theme_most_viewed) : ?>
    <div class="section section--popular" id="most-viewed">
        <div class="section-heading-row">
            <h2 class="section-heading"><?php esc_html_e('Xem Nhiều Nhất', 'tube-theme'); ?></h2>
            <?php if (null !== $tube_theme_most_viewed_url) : ?>
                <a class="section-view-all" href="<?php echo esc_url($tube_theme_most_viewed_url); ?>">
                    <?php esc_html_e('Xem tất cả', 'tube-theme'); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php get_template_part('template-parts/video-grid', null, ['videos' => $tube_theme_most_viewed]); ?>
    </div>
<?php endif; ?>

<?php
// cliptranhlinh's own "Có Thể Bạn Thích" section -- reuses the same
// generic mosaic.php clipphotvn's homepage already calls (see that call
// site above), just with its own heading text and, via
// site-cliptranhlinh.css, its own entirely different layout treatment.
// Placed after Most Viewed (not before Recently Added, unlike
// clipphotvn's placement) to match this brand's own requested section
// order: Mới Cập Nhật, Được Xem Nhiều, Có Thể Bạn Thích.
if ('cliptranhlinh' === tube_theme_site_brand()) {
    get_template_part(
        'template-parts/mosaic',
        null,
        [
            'videos'  => $tube_theme_most_viewed,
            'heading' => __('Có Thể Bạn Thích', 'tube-theme'),
        ]
    );
}
?>

<?php if (!$tube_theme_has_any_content) : ?>
    <div class="empty-state">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M4 5h16v14H4z" />
            <path d="M10 9l6 3-6 3z" fill="currentColor" stroke="none" />
        </svg>
        <p><?php esc_html_e('Chưa có video nào được đăng. Quay lại sau nhé.', 'tube-theme'); ?></p>
    </div>
<?php endif; ?>

<?php if ([] !== $tube_theme_categories) : ?>
    <div class="section section--categories">
        <div class="section-heading-row">
            <h2 class="section-heading"><?php esc_html_e('Danh Mục', 'tube-theme'); ?></h2>
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
