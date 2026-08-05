<?php
/**
 * Video single page (`/watch/{slug}/`).
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

get_header();

while (have_posts()) :
    the_post();

    $tube_theme_video_id = get_the_ID();
    $tube_theme_video_id = false === $tube_theme_video_id ? 0 : $tube_theme_video_id;

    $tube_theme_permalink = get_permalink($tube_theme_video_id);
    $tube_theme_permalink = false === $tube_theme_permalink ? home_url('/') : $tube_theme_permalink;

    $tube_theme_categories = get_the_terms($tube_theme_video_id, 'video_category');
    $tube_theme_categories = is_array($tube_theme_categories) ? $tube_theme_categories : [];

    $tube_theme_breadcrumb_items = [
        [
            'name' => __('Home', 'tube-theme'),
            'url'  => home_url('/'),
        ],
    ];

    if ([] !== $tube_theme_categories) {
        $tube_theme_first_category = $tube_theme_categories[0];
        $tube_theme_category_link  = get_term_link($tube_theme_first_category);

        $tube_theme_breadcrumb_items[] = [
            'name' => $tube_theme_first_category->name,
            'url'  => is_string($tube_theme_category_link) ? $tube_theme_category_link : home_url('/'),
        ];
    }

    $tube_theme_breadcrumb_items[] = [
        'name' => get_the_title(),
        'url'  => $tube_theme_permalink,
    ];

    get_template_part('template-parts/breadcrumbs', null, ['items' => $tube_theme_breadcrumb_items]);
    ?>

    <article class="video-single">
        <?php
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escapes every interpolated value (esc_url()/esc_attr()), verified in Phase 6.
        echo tube_player_get_embed_html($tube_theme_video_id, ['title' => get_the_title()]);
        ?>

        <h1 class="video-single__title"><?php the_title(); ?></h1>

        <?php
        $tube_theme_date = get_the_date();
        $tube_theme_date = is_string($tube_theme_date) ? $tube_theme_date : '';
        ?>
        <p class="video-single__meta">
            <?php echo esc_html($tube_theme_date); ?>
        </p>

        <?php if (has_excerpt()) : ?>
            <div class="video-single__description"><?php the_excerpt(); ?></div>
        <?php endif; ?>

        <?php if ([] !== $tube_theme_categories) : ?>
            <p class="video-single__terms">
                <?php foreach ($tube_theme_categories as $tube_theme_category) : ?>
                    <?php
                    $tube_theme_term_link = get_term_link($tube_theme_category);
                    $tube_theme_term_url  = is_string($tube_theme_term_link) ? $tube_theme_term_link : home_url('/');
                    ?>
                    <a href="<?php echo esc_url($tube_theme_term_url); ?>">
                        <?php echo esc_html($tube_theme_category->name); ?>
                    </a>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
    </article>

    <?php $tube_theme_related = tube_search_related_videos($tube_theme_video_id, 12); ?>
    <?php if ([] !== $tube_theme_related) : ?>
        <h2 class="section-heading"><?php esc_html_e('Related Videos', 'tube-theme'); ?></h2>
        <div class="video-grid">
            <?php foreach ($tube_theme_related as $tube_theme_related_video) : ?>
                <?php get_template_part('template-parts/video-card', null, ['video' => $tube_theme_related_video]); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php endwhile; ?>

<?php
get_footer();
