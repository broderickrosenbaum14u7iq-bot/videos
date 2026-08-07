<?php
/**
 * An actor/studio photo, or a placeholder icon if none is set — the one
 * place this markup exists, shared by `template-parts/profile-header.php`
 * and `page-templates/actors.php`/`studios.php`'s directory grids.
 *
 * Expects, via `get_template_part()`'s `$args`:
 * - `image_id` (int|null) — `Actor::$photo_image_id`/`Studio::$logo_image_id`.
 * - `alt` (string)
 * - `variant` (string, `header` or `directory`) — which CSS class set to render.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $args */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

$tube_theme_image_id = isset($args['image_id']) && is_int($args['image_id']) ? $args['image_id'] : null;
$tube_theme_alt      = isset($args['alt']) && is_string($args['alt']) ? $args['alt'] : '';
$tube_theme_variant  = isset($args['variant']) && 'directory' === $args['variant'] ? 'directory' : 'header';
$tube_theme_class    = 'header' === $tube_theme_variant ? 'profile-header__photo' : 'profile-directory__photo';

$tube_theme_photo_html = tube_player_get_profile_image_html($tube_theme_image_id, 'avatar', ['alt' => $tube_theme_alt]);

?>
<?php if ('' !== $tube_theme_photo_html) : ?>
    <div class="<?php echo esc_attr($tube_theme_class); ?>">
        <?php echo $tube_theme_photo_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </div>
<?php else : ?>
    <div class="<?php echo esc_attr($tube_theme_class . ' ' . $tube_theme_class . '--placeholder'); ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 12a5 5 0 100-10 5 5 0 000 10zM4 22a8 8 0 0116 0z" />
        </svg>
    </div>
<?php endif; ?>
