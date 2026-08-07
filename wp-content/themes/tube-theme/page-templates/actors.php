<?php
/**
 * Template Name: All Actors
 *
 * Actor directory — assignable to any WordPress Page, same precedent as
 * page-templates/trending.php (no dedicated URL in ARCHITECTURE.md
 * §15.1's frozen URL table, so no new rewrite rule). Paginated via
 * `tube_core_list_actors()`/`tube_core_count_actors()` (Phase 13).
 * Deliberately shows name + photo + link only, no per-actor video
 * count — `count_videos_for_actor()` is a live COUNT() per actor, and
 * calling it once per row on a paginated grid would be an N+1.
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
$tube_theme_total    = tube_core_count_actors();
$tube_theme_actors   = tube_core_list_actors($tube_theme_per_page, ($tube_theme_page - 1) * $tube_theme_per_page);

$tube_theme_permalink = get_permalink();
$tube_theme_base_url  = false === $tube_theme_permalink ? home_url('/') : $tube_theme_permalink;

?>

<h1><?php the_title(); ?></h1>

<?php if ([] === $tube_theme_actors) : ?>
    <div class="empty-state">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 12a5 5 0 100-10 5 5 0 000 10zM4 22a8 8 0 0116 0z" />
        </svg>
        <p><?php esc_html_e('No actors yet.', 'tube-theme'); ?></p>
    </div>
<?php else : ?>
    <div class="profile-directory">
        <?php foreach ($tube_theme_actors as $tube_theme_actor) : ?>
            <?php $tube_theme_actor_url = home_url('/actor/' . $tube_theme_actor->slug . '/'); ?>
            <a class="profile-directory__item" href="<?php echo esc_url($tube_theme_actor_url); ?>">
                <?php
                get_template_part(
                    'template-parts/profile-avatar',
                    null,
                    [
                        'image_id' => $tube_theme_actor->photo_image_id,
                        'alt'      => $tube_theme_actor->name,
                        'variant'  => 'directory',
                    ]
                );
                ?>
                <span class="profile-directory__name"><?php echo esc_html($tube_theme_actor->name); ?></span>
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
