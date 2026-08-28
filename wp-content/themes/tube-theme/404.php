<?php
/**
 * 404 (not found) page — reached both by WordPress's own native
 * not-found handling and by `Tube_Core\Content\Routing\
 * TermArchiveRouting::route_template()`'s explicit `set_404()` call for
 * an unknown actor/studio slug.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

get_header();

?>

<div class="empty-state">
    <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
    </svg>
    <h1><?php esc_html_e('Không Tìm Thấy Trang', 'tube-theme'); ?></h1>
    <p><?php esc_html_e('Trang bạn tìm không tồn tại.', 'tube-theme'); ?></p>
    <a class="hero__cta" href="<?php echo esc_url(home_url('/')); ?>">
        <?php esc_html_e('Về Trang Chủ', 'tube-theme'); ?>
    </a>
</div>

<?php
get_footer();
