<?php
/**
 * The header's "Categories" mega-menu panel — category links and a
 * "Browse by Studio" list (`tube_core_list_studios()`, Phase 13).
 *
 * Expects `$args['categories']` (`WP_Term[]`), passed by header.php —
 * fetched once there and reused for the mobile nav panel too, rather
 * than this template part issuing its own separate `get_terms()` call
 * for the same conceptual data.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $args */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

$tube_theme_categories = isset($args['categories']) && is_array($args['categories']) ? $args['categories'] : [];

/** @var WP_Term[] $tube_theme_categories */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

$tube_theme_studios = tube_core_list_studios(8);

?>
<div class="mega-menu">
    <div class="mega-menu__grid">
        <div>
            <h3 class="mega-menu__heading"><?php esc_html_e('Categories', 'tube-theme'); ?></h3>
            <?php if ([] === $tube_theme_categories) : ?>
                <p class="mega-menu__empty"><?php esc_html_e('No categories yet.', 'tube-theme'); ?></p>
            <?php else : ?>
                <ul class="mega-menu__list">
                    <?php foreach ($tube_theme_categories as $tube_theme_category) : ?>
                        <?php
                        $tube_theme_term_link = get_term_link($tube_theme_category);
                        $tube_theme_term_url  = is_string($tube_theme_term_link) ? $tube_theme_term_link : '';
                        ?>
                        <li>
                            <a class="mega-menu__link" href="<?php echo esc_url($tube_theme_term_url); ?>">
                                <?php echo esc_html($tube_theme_category->name); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div>
            <h3 class="mega-menu__heading"><?php esc_html_e('Browse by Studio', 'tube-theme'); ?></h3>
            <?php if ([] === $tube_theme_studios) : ?>
                <p class="mega-menu__empty"><?php esc_html_e('No studios yet.', 'tube-theme'); ?></p>
            <?php else : ?>
                <ul class="mega-menu__list">
                    <?php foreach ($tube_theme_studios as $tube_theme_studio) : ?>
                        <li>
                            <a
                                class="mega-menu__link"
                                href="<?php echo esc_url(home_url('/studio/' . $tube_theme_studio->slug . '/')); ?>"
                            >
                                <?php echo esc_html($tube_theme_studio->name); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
