<?php
/**
 * Template Name: All Studios
 *
 * Studio directory — same shape as page-templates/actors.php; see its
 * docblock for why this is a Page template and why no per-studio video
 * count is shown.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$tube_theme_per_page = 24;
$tube_theme_page     = tube_theme_current_page();
$tube_theme_total    = tube_core_count_studios();
$tube_theme_studios  = tube_core_list_studios($tube_theme_per_page, ($tube_theme_page - 1) * $tube_theme_per_page);

$tube_theme_permalink = get_permalink();
$tube_theme_base_url  = false === $tube_theme_permalink ? home_url('/') : $tube_theme_permalink;

?>

<h1><?php the_title(); ?></h1>

<?php if ([] === $tube_theme_studios) : ?>
    <div class="empty-state">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 12a5 5 0 100-10 5 5 0 000 10zM4 22a8 8 0 0116 0z" />
        </svg>
        <p><?php esc_html_e('Chưa có hãng phim nào.', 'tube-theme'); ?></p>
    </div>
<?php else : ?>
    <div class="profile-directory">
        <?php foreach ($tube_theme_studios as $tube_theme_studio) : ?>
            <?php $tube_theme_studio_url = home_url('/studio/' . $tube_theme_studio->slug . '/'); ?>
            <a class="profile-directory__item" href="<?php echo esc_url($tube_theme_studio_url); ?>">
                <?php
                get_template_part(
                    'template-parts/profile-avatar',
                    null,
                    [
                        'image_id' => $tube_theme_studio->logo_image_id,
                        'alt'      => $tube_theme_studio->name,
                        'variant'  => 'directory',
                    ]
                );
                ?>
                <span class="profile-directory__name"><?php echo esc_html($tube_theme_studio->name); ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php
    get_template_part(
        'template-parts/pagination',
        null,
        [
            'page'        => $tube_theme_page,
            'total_pages' => (int) ceil($tube_theme_total / $tube_theme_per_page),
            'page_url'    => static fn (int $tube_theme_target_page): string => $tube_theme_target_page > 1
                ? trailingslashit($tube_theme_base_url) . 'page/' . $tube_theme_target_page . '/'
                : $tube_theme_base_url,
        ]
    );
    ?>
<?php endif; ?>

<?php
get_footer();
