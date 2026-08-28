<?php
/**
 * "Tags Phổ Biến" — real video_tag terms with real usage counts
 * (WordPress's own `wp_term_taxonomy.count`, never hardcoded), each
 * linking to its real tag archive page.
 *
 * Expects, via `get_template_part()`'s `$args`:
 * - `tags` (WP_Term[]) — required, already fetched by the caller
 *   ({@see tube_theme_popular_tags()}) so this template issues zero
 *   queries of its own.
 * - `view_all_url` (string|null) — optional "Xem tất cả tags" link.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $args */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

$tube_theme_tags = isset($args['tags']) && is_array($args['tags']) ? $args['tags'] : [];

if ([] === $tube_theme_tags) {
    return;
}

/** @var WP_Term[] $tube_theme_tags */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

$tube_theme_view_all_url = isset($args['view_all_url']) && is_string($args['view_all_url'])
    ? $args['view_all_url']
    : null;

?>
<div class="popular-tags">
    <div class="popular-tags__heading-row">
        <h2 class="section-heading"><?php esc_html_e('Tags Phổ Biến', 'tube-theme'); ?></h2>
        <?php if (null !== $tube_theme_view_all_url) : ?>
            <a class="section-view-all" href="<?php echo esc_url($tube_theme_view_all_url); ?>">
                <?php esc_html_e('Xem tất cả tags', 'tube-theme'); ?>
            </a>
        <?php endif; ?>
    </div>
    <div class="popular-tags__list">
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
</div>
