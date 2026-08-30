<?php
/**
 * Video single page (`/watch/{slug}/`) — mobile-first "Phim Tối Cổ" watch
 * experience redesign.
 *
 * Desktop hierarchy: `.watch-layout` is a plain block on mobile (so the
 * DOM order below IS the mobile reading order: player, title, meta,
 * actions, description, tags, related, trending — Part 21's exact
 * requirement) and becomes a two-column CSS grid at >=1024px
 * (`.watch-layout__main` ~70%, `.watch-layout__sidebar` ~25-30%) —
 * `assets/css/tube-theme.css`'s responsive section. No duplicated
 * markup for the two layouts.
 *
 * Every data source below already existed before this redesign
 * (`tube_search_get_video()`, `tube_core_has_liked()`/`_has_saved()`/
 * `_likes_total()`, `tube_search_related_videos()`, `tube_search_trending()`)
 * — this file only changed how they're arranged and presented. The
 * click-to-load Cloudflare Stream player itself
 * (`tube_player_get_embed_html()`) and its view-recording trigger are
 * untouched; the duration badge overlaid on it is a pure-CSS sibling
 * (see `.video-player-wrap__duration`'s own CSS comment for how it
 * disappears on activation without touching tube-player.js).
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

    $tube_theme_tags = get_the_terms($tube_theme_video_id, 'video_tag');
    $tube_theme_tags = is_array($tube_theme_tags) ? $tube_theme_tags : [];

    $tube_theme_indexed = tube_search_get_video($tube_theme_video_id);

    $tube_theme_views_total   = null === $tube_theme_indexed ? 0 : $tube_theme_indexed->views_total;
    $tube_theme_duration_text = tube_theme_format_duration(
        null === $tube_theme_indexed ? null : $tube_theme_indexed->duration_seconds
    );
    $tube_theme_relative_time = tube_theme_relative_time(
        null === $tube_theme_indexed ? '' : ( (string) $tube_theme_indexed->published_at)
    );

    $tube_theme_liked       = tube_core_has_liked($tube_theme_video_id);
    $tube_theme_likes_total = tube_core_likes_total($tube_theme_video_id);
    $tube_theme_saved       = tube_core_has_saved($tube_theme_video_id);

    $tube_theme_breadcrumb_items = [
        [
            'name' => __('Trang chủ', 'tube-theme'),
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

    // Discovery chips: the same fixed-trio + real category/tag shortcuts
    // shape template-parts/discovery-chips.php already renders on the
    // homepage (front-page.php's own docblock has the full reasoning for
    // each fallback URL) — reused here so a visitor mid-watch can jump
    // straight to another discovery surface without opening the full
    // mobile-nav drawer.
    $tube_theme_watch_chip_categories = get_terms(
        [
            'taxonomy'   => 'video_category',
            'hide_empty' => true,
            'number'     => 6,
            'orderby'    => 'name',
        ]
    );
    $tube_theme_watch_chip_categories = is_array($tube_theme_watch_chip_categories)
        ? $tube_theme_watch_chip_categories
        : [];
    $tube_theme_watch_chip_tags       = tube_theme_popular_tags(6);

    $tube_theme_watch_primary_new_label = 'dongtoico' === tube_theme_site_brand()
        ? __('Mới Nhất', 'tube-theme')
        : __('Video Mới', 'tube-theme');

    $tube_theme_watch_chips = [
        [
            'label' => '🆕 ' . $tube_theme_watch_primary_new_label,
            'url'   => tube_theme_page_template_url('page-templates/latest.php') ?? home_url('/#latest'),
            'type'  => 'primary-new',
        ],
        [
            'label' => '🔥 ' . __('Thịnh Hành', 'tube-theme'),
            'url'   => tube_theme_page_template_url('page-templates/trending.php') ?? home_url('/#trending'),
            'type'  => 'primary-trending',
        ],
        [
            'label' => '👀 ' . __('Xem Nhiều', 'tube-theme'),
            'url'   => tube_theme_page_template_url('page-templates/most-viewed.php') ?? home_url('/#most-viewed'),
            'type'  => 'primary-popular',
        ],
    ];

    foreach ($tube_theme_watch_chip_categories as $tube_theme_watch_chip_category) {
        $tube_theme_watch_chip_link = get_term_link($tube_theme_watch_chip_category);

        if (is_string($tube_theme_watch_chip_link)) {
            $tube_theme_watch_chip_meta = tube_theme_discovery_category_meta($tube_theme_watch_chip_category->slug);

            $tube_theme_watch_chips[] = [
                'label'       => $tube_theme_watch_chip_meta['emoji'] . ' ' . $tube_theme_watch_chip_category->name,
                'url'         => $tube_theme_watch_chip_link,
                'type'        => 'category',
                'color_class' => $tube_theme_watch_chip_meta['color_class']
                    ?? tube_theme_discovery_category_color_class($tube_theme_watch_chip_category->term_id),
            ];
        }
    }

    foreach ($tube_theme_watch_chip_tags as $tube_theme_watch_chip_tag) {
        $tube_theme_watch_chip_link = get_term_link($tube_theme_watch_chip_tag);

        if (is_string($tube_theme_watch_chip_link)) {
            $tube_theme_watch_chips[] = [
                'label'       => '#' . $tube_theme_watch_chip_tag->name,
                'url'         => $tube_theme_watch_chip_link,
                'type'        => 'tag',
                'color_class' => tube_theme_tag_color_class($tube_theme_watch_chip_tag->term_id),
            ];
        }
    }

    get_template_part('template-parts/discovery-chips', null, ['chips' => $tube_theme_watch_chips]);
    get_template_part('template-parts/breadcrumbs', null, ['items' => $tube_theme_breadcrumb_items]);

    // One related-videos fetch (RelatedVideosFinder::find(), cached per
    // video_id for 15 min — ARCHITECTURE.md §12 Phase 7), split in PHP
    // rather than queried twice, and guaranteed non-overlapping via
    // array_slice offsets, not a second exclude-list query.
    //
    // Split boundary is 5, not 12: the sidebar's own CSS already hides
    // everything past its 5th card on desktop (.watch-layout__sidebar
    // .video-grid > *:nth-child(n + 6)), so a 12/rest split was handing
    // 7 fetched-but-never-rendered rows to the sidebar while starving
    // the main-column "Video Liên Quan" grid — the exact "huge empty
    // main area" this split exists to prevent. Mobile is unaffected by
    // this boundary in practice: this environment's real dataset never
    // has more than ~7 distinct related candidates for any one video,
    // so mobile's unsliced sidebar list (Part 11's "one recommendation
    // section") was already showing at most 7 items either way.
    $tube_theme_related       = tube_search_related_videos($tube_theme_video_id, 24);
    $tube_theme_related_aside = array_slice($tube_theme_related, 0, 5);
    $tube_theme_related_more  = array_slice($tube_theme_related, 5, 12);

    // 2026-08-28: "Video Liên Quan" (related_more) is now shown on every
    // width (see tube-theme.css's own `.watch-layout__related-main`
    // comment), so Trending -- always shown, a separate query -- can now
    // sit adjacent to it. Trending's own ranking is untouched
    // (`tube_search_trending()` itself, unchanged); this only filters
    // its ALREADY-fetched result set against video IDs the two related
    // sections already reserved, the same display-level exclusion
    // pattern the `!== $tube_theme_video_id` self-exclusion right below
    // already established. De-duplication is best-effort, not absolute:
    // if removing every related-video ID would leave Trending empty (a
    // real risk on a small catalog, not a hypothetical -- this
    // project's own dev dataset has 8 total videos), it falls back to
    // the merely self-excluded list rather than hiding the whole
    // section, matching "do not simply hide valuable content."
    $tube_theme_related_ids = array_map(
        static fn ($tube_theme_row): int => $tube_theme_row->video_id,
        array_merge($tube_theme_related_aside, $tube_theme_related_more)
    );

    $tube_theme_trending_self_excluded = array_values(
        array_filter(
            tube_search_trending(9),
            static fn ($tube_theme_row): bool => $tube_theme_row->video_id !== $tube_theme_video_id
        )
    );

    $tube_theme_trending_deduped = array_values(
        array_filter(
            $tube_theme_trending_self_excluded,
            static fn ($tube_theme_row): bool => !in_array($tube_theme_row->video_id, $tube_theme_related_ids, true)
        )
    );

    $tube_theme_trending = array_slice(
        [] !== $tube_theme_trending_deduped ? $tube_theme_trending_deduped : $tube_theme_trending_self_excluded,
        0,
        8
    );

    ?>

    <div class="watch-layout">
        <div class="watch-layout__main">
            <article class="video-single">
                <div class="video-player-wrap">
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escapes every interpolated value (esc_url()/esc_attr()), verified in Phase 6.
                    echo tube_player_get_embed_html(
                        $tube_theme_video_id,
                        [
                            'title' => get_the_title(),
                            'eager' => true,
                        ]
                    );
                    ?>
                    <?php if ('' !== $tube_theme_duration_text) : ?>
                        <span class="video-player-wrap__duration">
                            <?php echo esc_html($tube_theme_duration_text); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <h1 class="video-single__title"><?php the_title(); ?></h1>

                <p class="video-single__meta">
                    <span class="video-single__meta-item">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path
                                d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            />
                            <circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2" />
                        </svg>
                        <?php echo esc_html(tube_theme_compact_number($tube_theme_views_total)); ?>
                        <span class="screen-reader-text"><?php esc_html_e('lượt xem', 'tube-theme'); ?></span>
                    </span>
                    <?php if ('' !== $tube_theme_relative_time) : ?>
                        <span class="video-single__meta-item">
                            🕒 <?php echo esc_html($tube_theme_relative_time); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ('' !== $tube_theme_duration_text) : ?>
                        <span class="video-single__meta-item">
                            ⏱ <?php echo esc_html($tube_theme_duration_text); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ([] !== $tube_theme_categories) : ?>
                        <?php
                        $tube_theme_meta_category      = $tube_theme_categories[0];
                        $tube_theme_meta_category_link = get_term_link($tube_theme_meta_category);
                        $tube_theme_meta_category_url  = is_string($tube_theme_meta_category_link)
                            ? $tube_theme_meta_category_link
                            : home_url('/');
                        ?>
                        <a
                            class="video-single__meta-category"
                            href="<?php echo esc_url($tube_theme_meta_category_url); ?>"
                        >
                            🎬 <?php echo esc_html($tube_theme_meta_category->name); ?>
                        </a>
                    <?php endif; ?>
                </p>

                <?php
                get_template_part(
                    'template-parts/video-actions',
                    null,
                    [
                        'video_id'    => $tube_theme_video_id,
                        'permalink'   => $tube_theme_permalink,
                        'title'       => get_the_title(),
                        'liked'       => $tube_theme_liked,
                        'likes_total' => $tube_theme_likes_total,
                        'saved'       => $tube_theme_saved,
                    ]
                );
                ?>

                <?php if (has_excerpt()) : ?>
                    <div class="video-description" data-tube-description>
                        <h2 class="video-description__heading">📝 <?php esc_html_e('Mô tả', 'tube-theme'); ?></h2>
                        <div class="video-description__content" data-tube-description-content>
                            <?php the_excerpt(); ?>
                        </div>
                        <button
                            type="button"
                            class="video-description__toggle"
                            data-tube-description-toggle
                            hidden
                        >
                            <span data-tube-description-toggle-label>
                                <?php esc_html_e('Xem thêm', 'tube-theme'); ?>
                            </span>
                        </button>
                    </div>
                <?php endif; ?>

                <?php if ([] !== $tube_theme_tags) : ?>
                    <div class="video-single__tags-block">
                        <h2 class="video-single__tags-heading">🏷️ <?php esc_html_e('Thẻ', 'tube-theme'); ?></h2>
                        <p class="video-single__tags">
                            <?php foreach ($tube_theme_tags as $tube_theme_tag) : ?>
                                <?php
                                $tube_theme_tag_link  = get_term_link($tube_theme_tag);
                                $tube_theme_tag_url   = is_string($tube_theme_tag_link)
                                    ? $tube_theme_tag_link
                                    : home_url('/');
                                $tube_theme_tag_class = 'tag-chip tag-chip--lg '
                                    . tube_theme_tag_color_class($tube_theme_tag->term_id);
                                ?>
                                <a
                                    class="<?php echo esc_attr($tube_theme_tag_class); ?>"
                                    href="<?php echo esc_url($tube_theme_tag_url); ?>"
                                >
                                    <?php echo esc_html($tube_theme_tag->name); ?>
                                </a>
                            <?php endforeach; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </article>

            <?php if (function_exists('tube_comments_render_section')) : ?>
                <div class="watch-layout__comments">
                    <?php tube_comments_render_section($tube_theme_video_id); ?>
                </div>
            <?php endif; ?>

            <?php if ([] !== $tube_theme_related_more) : ?>
                <div class="watch-layout__related-main">
                    <div class="section">
                        <h2 class="section-heading">🎬 <?php esc_html_e('Video Liên Quan', 'tube-theme'); ?></h2>
                        <?php
                        get_template_part(
                            'template-parts/video-grid',
                            null,
                            ['videos' => $tube_theme_related_more]
                        );
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ([] !== $tube_theme_trending) : ?>
                <div class="watch-layout__trending">
                    <div class="section">
                        <h2 class="section-heading">🔥 <?php esc_html_e('Đang Thịnh Hành', 'tube-theme'); ?></h2>
                        <?php
                        get_template_part(
                            'template-parts/video-grid',
                            null,
                            ['videos' => $tube_theme_trending]
                        );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="watch-layout__sidebar">
            <?php if ([] !== $tube_theme_related_aside) : ?>
                <div class="section">
                    <h2 class="section-heading">✨ <?php esc_html_e('Có Thể Bạn Sẽ Thích', 'tube-theme'); ?></h2>
                    <?php
                    get_template_part(
                        'template-parts/video-grid',
                        null,
                        ['videos' => $tube_theme_related_aside]
                    );
                    ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php endwhile; ?>

<?php
get_footer();
