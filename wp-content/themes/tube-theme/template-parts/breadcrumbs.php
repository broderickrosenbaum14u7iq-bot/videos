<?php
/**
 * A visual breadcrumb trail.
 *
 * Expects `$args['items']` (list<array{name: string, url: string}>), passed via `get_template_part()`.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $args */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

if (!isset($args['items']) || !is_array($args['items']) || [] === $args['items']) {
    return;
}

/** @var list<array{name: string, url: string}> $tube_theme_items */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_items = $args['items'];

$tube_theme_last_index = count($tube_theme_items) - 1;

?>
<nav class="breadcrumbs" aria-label="<?php echo esc_attr__('Breadcrumb', 'tube-theme'); ?>">
    <?php foreach ($tube_theme_items as $tube_theme_index => $tube_theme_item) : ?>
        <?php if (0 === $tube_theme_index) : ?>
            <a class="breadcrumbs__home" href="<?php echo esc_url($tube_theme_item['url']); ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9" fill="none" stroke="currentColor" stroke-width="2" />
                </svg>
                <span class="screen-reader-text"><?php echo esc_html($tube_theme_item['name']); ?></span>
            </a>
        <?php elseif ($tube_theme_index === $tube_theme_last_index) : ?>
            <span class="breadcrumbs__sep" aria-hidden="true">&rsaquo;</span>
            <span class="breadcrumbs__current" aria-current="page">
                <?php echo esc_html($tube_theme_item['name']); ?>
            </span>
        <?php else : ?>
            <span class="breadcrumbs__sep" aria-hidden="true">&rsaquo;</span>
            <a href="<?php echo esc_url($tube_theme_item['url']); ?>">
                <?php echo esc_html($tube_theme_item['name']); ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
