<?php
/**
 * The theme footer: closes <main>, renders the site footer, closes </body>/</html>.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$tube_theme_year = gmdate('Y');
?></main>
<footer class="site-footer">
    <p>&copy; <?php echo esc_html($tube_theme_year); ?> <?php bloginfo('name'); ?></p>
</footer>
<?php wp_footer(); ?>
</body>
</html>
