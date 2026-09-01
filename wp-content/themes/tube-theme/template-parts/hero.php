<?php
/**
 * Homepage hero banner — auto-populated from the current #1 trending
 * video (Phase 13 decision #2: no new data model, no "featured" flag).
 *
 * Expects `$args['video']` (a `Tube_Search\Index\SearchIndexRow`), passed
 * via `get_template_part()`. Renders nothing if null — `front-page.php`
 * only calls this when a trending video actually exists.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

use Tube_Search\Index\SearchIndexRow;

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $args */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

if (!isset($args['video']) || !($args['video'] instanceof SearchIndexRow)) {
    return;
}

/** @var SearchIndexRow $tube_theme_video */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_video = $args['video'];

$tube_theme_permalink = get_permalink($tube_theme_video->video_id);
$tube_theme_permalink = false === $tube_theme_permalink ? '' : $tube_theme_permalink;

// Multi-site visual identity (`docs/DEPLOY_NEW_SITE.md`): a brand opted
// into its own featured+trending composition renders it here and returns
// early. Every other site (the 'default' brand included) falls through
// to the exact original single-video hero below, unchanged.
if ('dongtoico' === tube_theme_site_brand()) {
    /** @var SearchIndexRow[] $tube_theme_hero_side */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
    $tube_theme_hero_side = isset($args['trending']) && is_array($args['trending'])
        ? array_slice($args['trending'], 0, 4)
        : [];
    ?>
    <section class="hero hero--dongtoico<?php echo [] !== $tube_theme_hero_side ? ' hero--has-side' : ''; ?>">
        <a class="hero__featured" href="<?php echo esc_url($tube_theme_permalink); ?>">
            <div class="hero__media">
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escapes every interpolated value (esc_url()/esc_attr()), verified in Phase 6.
                echo tube_player_get_image_html(
                    $tube_theme_video->video_id,
                    'hero',
                    [
                        'eager'         => true,
                        'fetchpriority' => 'high',
                        'alt'           => $tube_theme_video->title,
                    ]
                );
                ?>
            </div>
            <div class="hero__scrim"></div>
            <div class="hero__content">
                <span class="hero__eyebrow"><?php esc_html_e('#1 Thịnh Hành', 'tube-theme'); ?></span>
                <h1 class="hero__title"><?php echo esc_html($tube_theme_video->title); ?></h1>
                <p class="hero__meta">
                    <?php
                    printf(
                        /* translators: %s: formatted view count. */
                        esc_html__('%s lượt xem', 'tube-theme'),
                        esc_html(tube_theme_compact_number($tube_theme_video->views_total))
                    );
                    ?>
                </p>
                <span class="hero__cta">
                    ▶ <?php esc_html_e('Xem ngay', 'tube-theme'); ?>
                </span>
            </div>
        </a>
        <?php if ([] !== $tube_theme_hero_side) : ?>
            <div class="hero__side">
                <?php foreach ($tube_theme_hero_side as $tube_theme_side_video) : ?>
                    <?php
                    $tube_theme_side_permalink = get_permalink($tube_theme_side_video->video_id);
                    $tube_theme_side_permalink = false === $tube_theme_side_permalink ? '' : $tube_theme_side_permalink;
                    $tube_theme_side_duration  = tube_theme_format_duration($tube_theme_side_video->duration_seconds);
                    ?>
                    <a class="hero__side-card" href="<?php echo esc_url($tube_theme_side_permalink); ?>">
                        <span class="hero__side-thumb">
                            <?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escapes every interpolated value (esc_url()/esc_attr()), verified in Phase 6.
                            echo tube_player_get_image_html(
                                $tube_theme_side_video->video_id,
                                'grid_card',
                                ['alt' => $tube_theme_side_video->title]
                            );
                            ?>
                            <?php if ('' !== $tube_theme_side_duration) : ?>
                                <span class="hero__side-duration"><?php echo esc_html($tube_theme_side_duration); ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="hero__side-body">
                            <span class="hero__side-title"><?php echo esc_html($tube_theme_side_video->title); ?></span>
                            <span class="hero__side-views">
                                <?php
                                printf(
                                    /* translators: %s: formatted view count. */
                                    esc_html__('%s lượt xem', 'tube-theme'),
                                    esc_html(tube_theme_compact_number($tube_theme_side_video->views_total))
                                );
                                ?>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php

    return;
}

// clipbanquat's own "Spotlight" composition -- structurally similar to
// dongtoico's (featured + side stack) but kept as an entirely separate
// branch/class namespace (hero--clipbanquat, never hero--dongtoico) so
// this can carry clipbanquat-specific data (duration + first category
// on the featured item, which dongtoico's own composition intentionally
// does not show) with zero risk of altering dongtoico's own unchanged
// output.
if ('clipbanquat' === tube_theme_site_brand()) {
    /** @var SearchIndexRow[] $tube_theme_spotlight_side */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
    $tube_theme_spotlight_side = isset($args['trending']) && is_array($args['trending'])
        ? array_slice($args['trending'], 0, 3)
        : [];

    $tube_theme_spotlight_duration = tube_theme_format_duration($tube_theme_video->duration_seconds);

    $tube_theme_spotlight_categories = get_the_terms($tube_theme_video->video_id, 'video_category');
    $tube_theme_spotlight_category   = is_array($tube_theme_spotlight_categories) && [] !== $tube_theme_spotlight_categories
        ? $tube_theme_spotlight_categories[0]
        : null;
    ?>
    <section class="hero hero--clipbanquat<?php echo [] !== $tube_theme_spotlight_side ? ' hero--has-side' : ''; ?>">
        <a class="hero__featured" href="<?php echo esc_url($tube_theme_permalink); ?>">
            <div class="hero__media">
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escapes every interpolated value (esc_url()/esc_attr()), verified in Phase 6.
                echo tube_player_get_image_html(
                    $tube_theme_video->video_id,
                    'hero',
                    [
                        'eager'         => true,
                        'fetchpriority' => 'high',
                        'alt'           => $tube_theme_video->title,
                    ]
                );
                ?>
            </div>
            <div class="hero__scrim"></div>
            <?php if ('' !== $tube_theme_spotlight_duration) : ?>
                <span class="hero__duration"><?php echo esc_html($tube_theme_spotlight_duration); ?></span>
            <?php endif; ?>
            <div class="hero__content">
                <span class="hero__eyebrow"><?php esc_html_e('Nổi Bật', 'tube-theme'); ?></span>
                <h1 class="hero__title"><?php echo esc_html($tube_theme_video->title); ?></h1>
                <p class="hero__meta">
                    <?php
                    printf(
                        /* translators: %s: formatted view count. */
                        esc_html__('%s lượt xem', 'tube-theme'),
                        esc_html(tube_theme_compact_number($tube_theme_video->views_total))
                    );
                    ?>
                    <?php if (null !== $tube_theme_spotlight_category) : ?>
                        <span class="hero__meta-category"><?php echo esc_html($tube_theme_spotlight_category->name); ?></span>
                    <?php endif; ?>
                </p>
                <span class="hero__cta">
                    ▶ <?php esc_html_e('Xem ngay', 'tube-theme'); ?>
                </span>
            </div>
        </a>
        <?php if ([] !== $tube_theme_spotlight_side) : ?>
            <div class="hero__side">
                <?php foreach ($tube_theme_spotlight_side as $tube_theme_side_video) : ?>
                    <?php
                    $tube_theme_side_permalink = get_permalink($tube_theme_side_video->video_id);
                    $tube_theme_side_permalink = false === $tube_theme_side_permalink ? '' : $tube_theme_side_permalink;
                    $tube_theme_side_duration  = tube_theme_format_duration($tube_theme_side_video->duration_seconds);
                    ?>
                    <a class="hero__side-card" href="<?php echo esc_url($tube_theme_side_permalink); ?>">
                        <span class="hero__side-thumb">
                            <?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escapes every interpolated value (esc_url()/esc_attr()), verified in Phase 6.
                            echo tube_player_get_image_html(
                                $tube_theme_side_video->video_id,
                                'grid_card',
                                ['alt' => $tube_theme_side_video->title]
                            );
                            ?>
                            <?php if ('' !== $tube_theme_side_duration) : ?>
                                <span class="hero__side-duration"><?php echo esc_html($tube_theme_side_duration); ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="hero__side-body">
                            <span class="hero__side-title"><?php echo esc_html($tube_theme_side_video->title); ?></span>
                            <span class="hero__side-views">
                                <?php
                                printf(
                                    /* translators: %s: formatted view count. */
                                    esc_html__('%s lượt xem', 'tube-theme'),
                                    esc_html(tube_theme_compact_number($tube_theme_side_video->views_total))
                                );
                                ?>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php

    return;
}

// clipphotvn's own cinematic composition -- deliberately NOT the
// featured+side split dongtoico/clipbanquat both use (brief's own "do
// not use the same 65/35 layout as clipbanquat"): a full-width hero
// (the shared default single-hero markup below, reused as-is -- same
// class names, so it inherits zero clipbanquat/dongtoico CSS and needs
// none of its own structural markup), followed by a separate small
// horizontal rail of up to 3 trending items directly underneath.
if ('clipphotvn' === tube_theme_site_brand()) {
    /** @var SearchIndexRow[] $tube_theme_rail_videos */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
    $tube_theme_rail_videos = isset($args['trending']) && is_array($args['trending'])
        ? array_slice($args['trending'], 0, 3)
        : [];

    $tube_theme_hero_category = get_the_terms($tube_theme_video->video_id, 'video_category');
    $tube_theme_hero_category = is_array($tube_theme_hero_category) && [] !== $tube_theme_hero_category
        ? $tube_theme_hero_category[0]
        : null;

    $tube_theme_hero_duration = tube_theme_format_duration($tube_theme_video->duration_seconds);
    ?>
    <section class="hero hero--clipphotvn">
        <div class="hero__media">
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escapes every interpolated value (esc_url()/esc_attr()), verified in Phase 6.
            echo tube_player_get_image_html(
                $tube_theme_video->video_id,
                'hero',
                [
                    'eager'         => true,
                    'fetchpriority' => 'high',
                    'alt'           => $tube_theme_video->title,
                ]
            );
            ?>
        </div>
        <div class="hero__scrim"></div>
        <?php if ('' !== $tube_theme_hero_duration) : ?>
            <span class="hero__duration"><?php echo esc_html($tube_theme_hero_duration); ?></span>
        <?php endif; ?>
        <div class="hero__content">
            <span class="hero__eyebrow"><?php esc_html_e('Nổi Bật', 'tube-theme'); ?></span>
            <h1 class="hero__title"><?php echo esc_html($tube_theme_video->title); ?></h1>
            <p class="hero__meta">
                <?php
                printf(
                    /* translators: %s: formatted view count. */
                    esc_html__('%s lượt xem', 'tube-theme'),
                    esc_html(tube_theme_compact_number($tube_theme_video->views_total))
                );
                ?>
                <?php if (null !== $tube_theme_hero_category) : ?>
                    <span class="hero__meta-category"><?php echo esc_html($tube_theme_hero_category->name); ?></span>
                <?php endif; ?>
            </p>
            <a class="hero__cta" href="<?php echo esc_url($tube_theme_permalink); ?>">
                ▶ <?php esc_html_e('Xem ngay', 'tube-theme'); ?>
            </a>
        </div>
    </section>
    <?php if ([] !== $tube_theme_rail_videos) : ?>
        <div class="hero-rail">
            <?php foreach ($tube_theme_rail_videos as $tube_theme_rail_video) : ?>
                <?php
                $tube_theme_rail_permalink = get_permalink($tube_theme_rail_video->video_id);
                $tube_theme_rail_permalink = false === $tube_theme_rail_permalink ? '' : $tube_theme_rail_permalink;
                $tube_theme_rail_duration  = tube_theme_format_duration($tube_theme_rail_video->duration_seconds);
                ?>
                <a class="hero-rail__item" href="<?php echo esc_url($tube_theme_rail_permalink); ?>">
                    <span class="hero-rail__thumb">
                        <?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escapes every interpolated value (esc_url()/esc_attr()), verified in Phase 6.
                        echo tube_player_get_image_html(
                            $tube_theme_rail_video->video_id,
                            'grid_card',
                            ['alt' => $tube_theme_rail_video->title]
                        );
                        ?>
                        <?php if ('' !== $tube_theme_rail_duration) : ?>
                            <span class="hero-rail__duration"><?php echo esc_html($tube_theme_rail_duration); ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="hero-rail__title"><?php echo esc_html($tube_theme_rail_video->title); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php

    return;
}

?>
<section class="hero">
    <div class="hero__media">
        <?php
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escapes every interpolated value (esc_url()/esc_attr()), verified in Phase 6.
        echo tube_player_get_image_html(
            $tube_theme_video->video_id,
            'hero',
            [
                'eager'         => true,
                'fetchpriority' => 'high',
                'alt'           => $tube_theme_video->title,
            ]
        );
        ?>
    </div>
    <div class="hero__scrim"></div>
    <div class="hero__content">
        <span class="hero__eyebrow"><?php esc_html_e('#1 Thịnh Hành', 'tube-theme'); ?></span>
        <h1 class="hero__title"><?php echo esc_html($tube_theme_video->title); ?></h1>
        <p class="hero__meta">
            <?php
            printf(
                /* translators: %s: formatted view count. */
                esc_html__('%s lượt xem', 'tube-theme'),
                esc_html(tube_theme_compact_number($tube_theme_video->views_total))
            );
            ?>
        </p>
        <a class="hero__cta" href="<?php echo esc_url($tube_theme_permalink); ?>">
            ▶ <?php esc_html_e('Xem ngay', 'tube-theme'); ?>
        </a>
    </div>
</section>
