<?php
/**
 * Shared body for every archive listing page (category/tag/actor/studio) —
 * heading, description, video grid, pagination. The one place this markup
 * exists, reused by 4 near-identical archive page templates.
 *
 * Expects, via `get_template_part()`'s `$args`:
 * - `title` (string)
 * - `description` (string)
 * - `result` (Tube_Search\Discovery\ArchivePage)
 * - `page` (int)
 * - `page_url` (callable(int): string)
 * - `empty_message` (string)
 * - `heading_tag` (string, optional, `h1` or `h2`; default `h1`) — actor/
 *   studio archives (Phase 13) pass `h2` since `template-parts/profile-header.php`
 *   already rendered the page's one `<h1>` (the person/studio's name);
 *   category/tag archives have no profile header, so their title here
 *   stays the page's real `<h1>`.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

use Tube_Search\Discovery\ArchivePage;

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $args */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

$tube_theme_has_required_args = isset(
    $args['title'],
    $args['result'],
    $args['page'],
    $args['page_url'],
    $args['empty_message']
);

if (!$tube_theme_has_required_args || !($args['result'] instanceof ArchivePage)) {
    return;
}

/** @var string $tube_theme_title */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_title = $args['title'];

/** @var string $tube_theme_description */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_description = isset($args['description']) && is_string($args['description']) ? $args['description'] : '';

/** @var ArchivePage $tube_theme_result */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_result = $args['result'];

/** @var int $tube_theme_page */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_page = $args['page'];

/** @var callable(int): string $tube_theme_page_url */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_page_url = $args['page_url'];

/** @var string $tube_theme_empty_message */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_empty_message = $args['empty_message'];

$tube_theme_heading_tag = isset($args['heading_tag']) && 'h2' === $args['heading_tag'] ? 'h2' : 'h1';

?>

<?php if ('h2' === $tube_theme_heading_tag) : ?>
    <h2 class="section-heading"><?php echo esc_html($tube_theme_title); ?></h2>
<?php else : ?>
    <h1><?php echo esc_html($tube_theme_title); ?></h1>
<?php endif; ?>

<?php if ('' !== $tube_theme_description) : ?>
    <div class="archive-description"><?php echo wp_kses_post($tube_theme_description); ?></div>
<?php endif; ?>

<?php
$tube_theme_total_pages = (int) ceil($tube_theme_result->total / $tube_theme_result->per_page);

get_template_part(
    'template-parts/video-grid',
    null,
    [
        'videos'        => $tube_theme_result->items,
        'empty_message' => $tube_theme_empty_message,
        'page'          => $tube_theme_page,
        'total_pages'   => $tube_theme_total_pages,
        'page_url'      => $tube_theme_page_url,
    ]
);
?>
