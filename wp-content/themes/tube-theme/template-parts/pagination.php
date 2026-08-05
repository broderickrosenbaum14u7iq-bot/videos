<?php
/**
 * Prev/next + page-number pagination links for a listing page.
 *
 * Expects, via `get_template_part()`'s `$args`:
 * - `page` (int): the current page number (1-indexed).
 * - `total_pages` (int): the total number of pages.
 * - `page_url` (callable(int): string): builds page N's URL.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $args */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

if (!isset($args['page'], $args['total_pages'], $args['page_url'])) {
    return;
}

/** @var int $tube_theme_page */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_page = $args['page'];

/** @var int $tube_theme_total_pages */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_total_pages = $args['total_pages'];

/** @var callable(int): string $tube_theme_page_url */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_page_url = $args['page_url'];

if ($tube_theme_total_pages < 2) {
    return;
}

?>
<nav class="pagination" aria-label="<?php echo esc_attr__('Pagination', 'tube-theme'); ?>">
    <?php if ($tube_theme_page > 1) : ?>
        <a rel="prev" href="<?php echo esc_url($tube_theme_page_url($tube_theme_page - 1)); ?>">
            <?php esc_html_e('Previous', 'tube-theme'); ?>
        </a>
    <?php endif; ?>

    <span class="current">
        <?php
        $tube_theme_page_str        = (string) $tube_theme_page;
        $tube_theme_total_pages_str = (string) $tube_theme_total_pages;

        printf(
            /* translators: 1: current page number, 2: total page count. */
            esc_html__('%1$s / %2$s', 'tube-theme'),
            esc_html($tube_theme_page_str),
            esc_html($tube_theme_total_pages_str)
        );
        ?>
    </span>

    <?php if ($tube_theme_page < $tube_theme_total_pages) : ?>
        <a rel="next" href="<?php echo esc_url($tube_theme_page_url($tube_theme_page + 1)); ?>">
            <?php esc_html_e('Next', 'tube-theme'); ?>
        </a>
    <?php endif; ?>
</nav>
