<?php
/**
 * clipphotvn's "editorial mosaic" homepage section — one large featured
 * card plus a 2x2 grid of 4 smaller cards, built from real Most Viewed
 * data (deliberately not the same rows front-page.php already used for
 * the hero/trending rail, so the homepage doesn't repeat the same few
 * videos three times over — see front-page.php's own call site for
 * exactly which slice this receives).
 *
 * Only ever called for the clipphotvn brand (front-page.php gates the
 * `get_template_part()` call itself); this file does not re-check the
 * brand, matching hero.php's own precedent for a `$args['video']`-gated
 * template-part that assumes its caller already decided when to render.
 *
 * Expects `$args['videos']` (a `Tube_Search\Index\SearchIndexRow[]`).
 * Renders nothing if fewer than 2 videos are available — a 1-item
 * "mosaic" is just a lone card, and there is no meaningful 2x2 to show.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

use Tube_Search\Index\SearchIndexRow;

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $args */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

$tube_theme_mosaic_videos = isset($args['videos']) && is_array($args['videos']) ? $args['videos'] : [];

if (count($tube_theme_mosaic_videos) < 2) {
    return;
}

/** @var SearchIndexRow $tube_theme_mosaic_main */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_mosaic_main = $tube_theme_mosaic_videos[0];
$tube_theme_mosaic_side = array_slice($tube_theme_mosaic_videos, 1, 4);

$tube_theme_mosaic_main_permalink = get_permalink($tube_theme_mosaic_main->video_id);
$tube_theme_mosaic_main_permalink = false === $tube_theme_mosaic_main_permalink ? '' : $tube_theme_mosaic_main_permalink;
$tube_theme_mosaic_main_duration  = tube_theme_format_duration($tube_theme_mosaic_main->duration_seconds);
?>
<div class="section section--mosaic">
    <div class="section-heading-row">
        <h2 class="section-heading"><?php esc_html_e('Dành Cho Bạn', 'tube-theme'); ?></h2>
    </div>
    <div class="mosaic">
        <a class="mosaic__main" href="<?php echo esc_url($tube_theme_mosaic_main_permalink); ?>">
            <span class="mosaic__main-media">
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escapes every interpolated value (esc_url()/esc_attr()), verified in Phase 6.
                echo tube_player_get_image_html(
                    $tube_theme_mosaic_main->video_id,
                    'hero',
                    ['alt' => $tube_theme_mosaic_main->title]
                );
                ?>
            </span>
            <span class="mosaic__main-scrim"></span>
            <?php if ('' !== $tube_theme_mosaic_main_duration) : ?>
                <span class="mosaic__main-duration"><?php echo esc_html($tube_theme_mosaic_main_duration); ?></span>
            <?php endif; ?>
            <span class="mosaic__main-body">
                <span class="mosaic__main-title"><?php echo esc_html($tube_theme_mosaic_main->title); ?></span>
                <span class="mosaic__main-views">
                    <?php
                    printf(
                        /* translators: %s: formatted view count. */
                        esc_html__('%s lượt xem', 'tube-theme'),
                        esc_html(tube_theme_compact_number($tube_theme_mosaic_main->views_total))
                    );
                    ?>
                </span>
            </span>
        </a>
        <?php if ([] !== $tube_theme_mosaic_side) : ?>
            <div class="mosaic__side">
                <?php foreach ($tube_theme_mosaic_side as $tube_theme_mosaic_item) : ?>
                    <?php
                    $tube_theme_mosaic_item_permalink = get_permalink($tube_theme_mosaic_item->video_id);
                    $tube_theme_mosaic_item_permalink = false === $tube_theme_mosaic_item_permalink ? '' : $tube_theme_mosaic_item_permalink;
                    $tube_theme_mosaic_item_duration  = tube_theme_format_duration($tube_theme_mosaic_item->duration_seconds);
                    ?>
                    <a class="mosaic__side-item" href="<?php echo esc_url($tube_theme_mosaic_item_permalink); ?>">
                        <span class="mosaic__side-media">
                            <?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escapes every interpolated value (esc_url()/esc_attr()), verified in Phase 6.
                            echo tube_player_get_image_html(
                                $tube_theme_mosaic_item->video_id,
                                'grid_card',
                                ['alt' => $tube_theme_mosaic_item->title]
                            );
                            ?>
                            <?php if ('' !== $tube_theme_mosaic_item_duration) : ?>
                                <span class="mosaic__side-duration"><?php echo esc_html($tube_theme_mosaic_item_duration); ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="mosaic__side-title"><?php echo esc_html($tube_theme_mosaic_item->title); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
