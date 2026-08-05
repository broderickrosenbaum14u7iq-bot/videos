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

<h1><?php esc_html_e('Page Not Found', 'tube-theme'); ?></h1>
<p><?php esc_html_e('The page you were looking for could not be found.', 'tube-theme'); ?></p>
<p><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Back to homepage', 'tube-theme'); ?></a></p>

<?php
get_footer();
