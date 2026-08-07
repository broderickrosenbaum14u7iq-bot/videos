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
        <?php if ($tube_theme_index === $tube_theme_last_index) : ?>
            <span aria-current="page"><?php echo esc_html($tube_theme_item['name']); ?></span>
        <?php else : ?>
            <a href="<?php echo esc_url($tube_theme_item['url']); ?>">
                <?php echo esc_html($tube_theme_item['name']); ?>
            </a> &raquo;
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
