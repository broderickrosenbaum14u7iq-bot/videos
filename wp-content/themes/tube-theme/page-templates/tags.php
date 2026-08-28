<?php
/**
 * Template Name: All Tags
 *
 * Tag directory — the "Xem tất cả tags" destination from
 * `template-parts/popular-tags.php`. Same shape as
 * `page-templates/actors.php`/`studios.php`: an ordinary, editor-
 * assignable WordPress Page (no dedicated URL in ARCHITECTURE.md §15.1's
 * frozen URL table, so no new rewrite rule). Every tag shows its real,
 * native WordPress taxonomy count (`WP_Term::$count`) — no per-tag
 * `COUNT()` query, safe at 100k+ daily visits the same way
 * `tube_theme_popular_tags()` already is.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$tube_theme_per_page = 60;
$tube_theme_page     = tube_theme_current_page();

$tube_theme_total_terms = wp_count_terms(
    [
        'taxonomy'   => 'video_tag',
        'hide_empty' => true,
    ]
);
$tube_theme_total       = is_numeric($tube_theme_total_terms) ? (int) $tube_theme_total_terms : 0;

$tube_theme_tags = get_terms(
    [
        'taxonomy'   => 'video_tag',
        'hide_empty' => true,
        'orderby'    => 'count',
        'order'      => 'DESC',
        'number'     => $tube_theme_per_page,
        'offset'     => ($tube_theme_page - 1) * $tube_theme_per_page,
    ]
);
$tube_theme_tags = is_array($tube_theme_tags) ? $tube_theme_tags : [];

$tube_theme_permalink = get_permalink();
$tube_theme_base_url  = false === $tube_theme_permalink ? home_url('/') : $tube_theme_permalink;

?>

<h1><?php the_title(); ?></h1>

<?php if ([] === $tube_theme_tags) : ?>
    <div class="empty-state">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M4 5h16v14H4z" />
            <path d="M10 9l6 3-6 3z" fill="currentColor" stroke="none" />
        </svg>
        <p><?php esc_html_e('Chưa có tag nào.', 'tube-theme'); ?></p>
    </div>
<?php else : ?>
    <div class="popular-tags__list popular-tags__list--directory">
        <?php foreach ($tube_theme_tags as $tube_theme_tag) : ?>
            <?php
            $tube_theme_tag_link  = get_term_link($tube_theme_tag);
            $tube_theme_tag_url   = is_string($tube_theme_tag_link) ? $tube_theme_tag_link : '';
            $tube_theme_tag_count = '(' . number_format_i18n($tube_theme_tag->count) . ')';
            $tube_theme_tag_class = 'tag-chip ' . tube_theme_tag_color_class($tube_theme_tag->term_id);
            ?>
            <a
                class="<?php echo esc_attr($tube_theme_tag_class); ?>"
                href="<?php echo esc_url($tube_theme_tag_url); ?>"
            >
                <?php echo esc_html($tube_theme_tag->name); ?>
                <span class="tag-chip__count"><?php echo esc_html($tube_theme_tag_count); ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php
    get_template_part(
        'template-parts/pagination',
        null,
        [
            'page'        => $tube_theme_page,
            'total_pages' => (int) ceil($tube_theme_total / $tube_theme_per_page),
            'page_url'    => static fn (int $tube_theme_target_page): string => $tube_theme_target_page > 1
                ? trailingslashit($tube_theme_base_url) . 'page/' . $tube_theme_target_page . '/'
                : $tube_theme_base_url,
        ]
    );
    ?>
<?php endif; ?>

<?php
get_footer();
