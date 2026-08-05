<?php
/**
 * One video card, for a grid listing.
 *
 * Expects `$args['video']` (a `Tube_Search\Index\SearchIndexRow`), passed via `get_template_part()`.
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
<a class="video-card" href="<?php echo esc_url($tube_theme_permalink); ?>">
    <?php
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escapes every interpolated value (esc_url()/esc_attr()), verified in Phase 6.
    echo tube_player_get_image_html($tube_theme_video->video_id, 'grid_card');
    ?>
    <p class="video-card__title"><?php echo esc_html($tube_theme_video->title); ?></p>
    <p class="video-card__meta">
        <?php
        printf(
            /* translators: %s: formatted view count. */
            esc_html__('%s views', 'tube-theme'),
            esc_html(number_format_i18n($tube_theme_video->views_total))
        );
        ?>
    </p>
</a>
