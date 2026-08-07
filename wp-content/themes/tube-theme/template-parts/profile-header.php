<?php
/**
 * The page's `<h1>` for an actor or studio archive — photo (or a
 * placeholder icon, via `template-parts/profile-avatar.php`), name, and
 * bio/description. Shared by archive-actor.php/archive-studio.php.
 *
 * Expects, via `get_template_part()`'s `$args`:
 * - `name` (string)
 * - `bio` (string|null)
 * - `image_id` (int|null) — `Actor::$photo_image_id`/`Studio::$logo_image_id`.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $args */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

if (!isset($args['name']) || !is_string($args['name'])) {
    return;
}

$tube_theme_name     = $args['name'];
$tube_theme_bio      = isset($args['bio']) && is_string($args['bio']) ? $args['bio'] : '';
$tube_theme_image_id = isset($args['image_id']) && is_int($args['image_id']) ? $args['image_id'] : null;

?>
<div class="profile-header">
    <?php
    get_template_part(
        'template-parts/profile-avatar',
        null,
        [
            'image_id' => $tube_theme_image_id,
            'alt'      => $tube_theme_name,
            'variant'  => 'header',
        ]
    );
    ?>
    <div class="profile-header__body">
        <h1 class="profile-header__name"><?php echo esc_html($tube_theme_name); ?></h1>
        <?php if ('' !== $tube_theme_bio) : ?>
            <p class="profile-header__bio"><?php echo esc_html($tube_theme_bio); ?></p>
        <?php endif; ?>
    </div>
</div>
