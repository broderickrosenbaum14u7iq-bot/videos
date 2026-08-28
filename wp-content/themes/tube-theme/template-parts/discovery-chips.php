<?php
/**
 * Discovery/filter chip group — homepage and watch-page only, directly
 * below the header. Every chip links to a real, already-working
 * destination (a page-template URL if an editor has assigned one, or an
 * anchor to that same page's own matching section otherwise) — no
 * fabricated features, no client-side filtering logic.
 *
 * Expects `$args['chips']` (list<array{label: string, url: string,
 * type: 'primary'|'category'|'tag', color_class?: string}>), passed by
 * front-page.php/single-video.php, which already assemble the fixed
 * "Video Mới/Thịnh Hành/Xem Nhiều" trio plus real category/tag
 * shortcuts. `type` drives this component's own three-tier visual
 * language (2026-08-28 topbar redesign — primary strongest accent,
 * category medium, tag subtlest); `color_class` is the deterministic
 * per-term color for category/tag chips
 * ({@see tube_theme_discovery_category_color_class()}/
 * {@see tube_theme_tag_color_class()}), omitted for `primary` chips
 * (those share one fixed accent, not a cycled per-item hue).
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $args */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

$tube_theme_chips = isset($args['chips']) && is_array($args['chips']) ? $args['chips'] : [];

if ([] === $tube_theme_chips) {
    return;
}

/** @var list<array{label: string, url: string, type: string, color_class?: string}> $tube_theme_chips */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
?>
<nav class="discovery-chips" aria-label="<?php echo esc_attr__('Khám phá nhanh', 'tube-theme'); ?>">
    <?php foreach ($tube_theme_chips as $tube_theme_chip) : ?>
        <?php
        $tube_theme_chip_class = 'discovery-chip discovery-chip--' . $tube_theme_chip['type'];

        if (isset($tube_theme_chip['color_class']) && '' !== $tube_theme_chip['color_class']) {
            $tube_theme_chip_class .= ' ' . $tube_theme_chip['color_class'];
        }
        ?>
        <a
            class="<?php echo esc_attr($tube_theme_chip_class); ?>"
            href="<?php echo esc_url($tube_theme_chip['url']); ?>"
        >
            <?php echo esc_html($tube_theme_chip['label']); ?>
        </a>
    <?php endforeach; ?>
</nav>
