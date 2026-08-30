<?php
/**
 * One video card, for a grid listing. Phase 13: thumbnail hover overlay
 * (play icon + zoom), duration badge, and a "starring" badge resolved
 * from `SearchIndexRow::$actor_ids`/`$studio_ids` via
 * `tube_core_get_actors()`/`tube_core_get_studios()` — cheap here only
 * because `template-parts/video-grid.php` already primed both
 * repositories' request-lifetime caches for the whole grid before this
 * card rendered (`inc/template-functions.php`'s
 * `tube_theme_prime_video_grid()`); this call issues zero additional
 * queries.
 *
 * Expects `$args['video']` (a `Tube_Search\Index\SearchIndexRow`), passed via `get_template_part()`.
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

use Tube_Search\Index\SearchIndexRow;

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $args */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

if (!isset($args['video']) || !($args['video'] instanceof SearchIndexRow)) {
    return;
}

/** @var SearchIndexRow $tube_theme_video */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
$tube_theme_video = $args['video'];

$tube_theme_permalink = get_permalink($tube_theme_video->video_id);
$tube_theme_permalink = false === $tube_theme_permalink ? '' : $tube_theme_permalink;

$tube_theme_duration = tube_theme_format_duration($tube_theme_video->duration_seconds);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escapes every interpolated value (esc_url()/esc_attr()), verified in Phase 6.
$tube_theme_poster_html = tube_player_get_image_html(
    $tube_theme_video->video_id,
    'grid_card',
    ['alt' => $tube_theme_video->title]
);
// '' means no WordPress poster is set for this video (ADR-0001 — never a
// Cloudflare Stream thumbnail fallback) -- template-parts/video-card.php's
// own docblock covers why this renders a deliberate placeholder instead
// of an empty box; see .video-card__thumb--empty's CSS for the visual.
$tube_theme_has_poster = '' !== $tube_theme_poster_html;

$tube_theme_badge_names = [];

if ([] !== $tube_theme_video->actor_ids) {
    foreach (tube_core_get_actors($tube_theme_video->actor_ids) as $tube_theme_actor) {
        $tube_theme_badge_names[] = $tube_theme_actor->name;
    }
}

if ([] === $tube_theme_badge_names && [] !== $tube_theme_video->studio_ids) {
    foreach (tube_core_get_studios($tube_theme_video->studio_ids) as $tube_theme_studio) {
        $tube_theme_badge_names[] = $tube_theme_studio->name;
    }
}

?>
<a class="video-card" href="<?php echo esc_url($tube_theme_permalink); ?>">
    <div class="video-card__thumb<?php echo $tube_theme_has_poster ? '' : ' video-card__thumb--empty'; ?>">
        <?php if ($tube_theme_has_poster) : ?>
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escapes every interpolated value, resolved above.
            echo $tube_theme_poster_html;
            ?>
        <?php else : ?>
            <span class="video-card__thumb-placeholder" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M4 5h16v14H4z" fill="none" stroke="currentColor" stroke-width="1.5" />
                    <path d="M10 9l6 3-6 3z" /></svg>
                <span class="video-card__thumb-mark"><?php echo esc_html(tube_theme_placeholder_brand_text()); ?></span>
            </span>
        <?php endif; ?>
        <span class="video-card__play" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
        </span>
        <?php if ('' !== $tube_theme_duration) : ?>
            <span class="video-card__duration"><?php echo esc_html($tube_theme_duration); ?></span>
        <?php endif; ?>
    </div>
    <p class="video-card__title"><?php echo esc_html($tube_theme_video->title); ?></p>
    <p class="video-card__meta">
        <?php
        printf(
            /* translators: %s: formatted view count. */
            esc_html__('%s lượt xem', 'tube-theme'),
            esc_html(tube_theme_compact_number($tube_theme_video->views_total))
        );
        ?>
    </p>
    <?php if ([] !== $tube_theme_badge_names) : ?>
        <p class="video-card__badges"><?php echo esc_html(implode(', ', $tube_theme_badge_names)); ?></p>
    <?php endif; ?>
</a>
