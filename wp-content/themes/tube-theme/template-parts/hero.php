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
