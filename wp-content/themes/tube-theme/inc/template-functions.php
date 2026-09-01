<?php
/**
 * Small template-local helpers shared across this theme's page templates.
 *
 * No `ABSPATH` guard here — `functions.php` already exits before
 * `require_once`-ing this file (the same reasoning every plugin's own
 * `includes/template-tags.php` documents).
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

use Tube_Search\Index\SearchIndexRow;

/**
 * The cache-busting `$ver` for one of this theme's own CSS/JS files —
 * the file's own mtime, not the fixed `TUBE_THEME_VERSION` theme-version
 * constant. This project's static assets are served with a long
 * (30-day) `Cache-Control` by nginx, keyed only on the request URL
 * (which includes `$ver`); with a version string that never changes
 * between edits, a browser that fetched a stylesheet before a visual
 * fix would keep serving that exact stale copy for up to a month even
 * though the file on disk is already correct -- confirmed live during
 * QA (2026-08-27): the tag-chip/discovery-chip/action-button CSS on
 * disk already matched the approved design exactly (7px/6px radii,
 * strengthened colors, no 999px pill anywhere), yet a real browser
 * could still be showing the pre-redesign look because
 * `tube-theme.css?ver=1.3.0` never changed URL across that whole
 * redesign. Same root cause, same fix already applied to `tube-ads`'s
 * own JS assets in an earlier session -- see
 * `Tube_Ads\Plugin::asset_version()`'s own docblock for the original
 * diagnosis this mirrors. `filemtime()` ties the query string to the
 * file's actual last-modified time, so any future edit is a new URL
 * and can never collide with a previously cached copy.
 *
 * @param string $relative_path Path under this theme's own directory, e.g. `assets/css/tube-theme.css`.
 */
function tube_theme_asset_version(string $relative_path): string
{
    $path = get_stylesheet_directory() . '/' . $relative_path;

    if (! file_exists($path)) {
        return TUBE_THEME_VERSION;
    }

    $mtime = filemtime($path);

    return false !== $mtime ? (string) $mtime : TUBE_THEME_VERSION;
}

/**
 * This site's visual-identity brand slug, for multi-site hosting where
 * more than one independent WordPress install shares this exact theme
 * codebase (`docs/DEPLOY_NEW_SITE.md`). Reads the optional
 * `TUBE_THEME_SITE_BRAND` wp-config.php constant (set per-site, never
 * committed) — a site that doesn't define it (the default/original
 * install) always resolves to `'default'` and renders byte-identical to
 * before this feature existed: every consumer of this function
 * (functions.php's brand stylesheet enqueue + `body_class` filter,
 * hero.php, video-card.php) only ever branches on a NON-default value.
 *
 * `sanitize_html_class()` keeps the returned value safe to use directly
 * in a CSS class name and a file path fragment (the brand stylesheet's
 * own filename, `site-{$brand}.css`) without separately validating both.
 */
function tube_theme_site_brand(): string
{
    $brand = defined('TUBE_THEME_SITE_BRAND') ? TUBE_THEME_SITE_BRAND : 'default';
    $brand = is_string($brand) ? sanitize_html_class($brand) : '';

    return '' !== $brand ? $brand : 'default';
}

/**
 * The empty-thumbnail placeholder's brand mark (template-parts/video-card.php).
 * Kept as a literal string per brand, NOT `get_bloginfo('name')` — this
 * displays only inside a fixed poster-shaped box as a small design
 * element, not as the site's actual name, and the original site's own
 * literal text must stay pixel-identical regardless of whatever its
 * `blogname` option happens to be set to.
 */
function tube_theme_placeholder_brand_text(): string
{
    return match (tube_theme_site_brand()) {
        'dongtoico'   => 'Đồng Tối Cổ',
        'clipbanquat' => 'Clip Bán Quạt',
        'clipphotvn'  => 'Clip Hot VN',
        default       => 'Phim Tối Cổ',
    };
}

/**
 * The three fixed primary discovery-chip labels (front-page.php,
 * single-video.php) — same three real destinations (Latest/Trending/
 * Most-Viewed page-template URLs) for every brand, only the wording
 * differs. Keyed the same as the `type` each chip already carries
 * (`discovery-chips.php`'s own `--primary-new/-trending/-popular`).
 *
 * @return array{new: string, trending: string, popular: string}
 */
function tube_theme_primary_chip_labels(): array
{
    return match (tube_theme_site_brand()) {
        'clipbanquat' => [
            'new'      => __('Mới Đăng', 'tube-theme'),
            'trending' => __('Đang Hot', 'tube-theme'),
            'popular'  => __('Xem Nhiều', 'tube-theme'),
        ],
        'clipphotvn' => [
            'new'      => __('Mới Nhất', 'tube-theme'),
            'trending' => __('Nổi Bật', 'tube-theme'),
            'popular'  => __('Xem Nhiều', 'tube-theme'),
        ],
        'dongtoico' => [
            'new'      => __('Mới Nhất', 'tube-theme'),
            'trending' => __('Thịnh Hành', 'tube-theme'),
            'popular'  => __('Xem Nhiều', 'tube-theme'),
        ],
        default => [
            'new'      => __('Video Mới', 'tube-theme'),
            'trending' => __('Thịnh Hành', 'tube-theme'),
            'popular'  => __('Xem Nhiều', 'tube-theme'),
        ],
    };
}

/**
 * Batch-prime both WordPress's own post cache and tube-player's video-
 * metadata cache for a list of videos about to be rendered as
 * `template-parts/video-card`s — call once before the loop, not per card.
 *
 * Added in Phase 11 after an audit found every grid template
 * (`front-page.php`, `search.php`, `page-templates/*.php`,
 * `template-parts/archive-listing.php`, `single-video.php`'s related-
 * videos block) rendering `video-card.php` in a loop with no batching:
 * each card's `get_permalink()` and `tube_player_get_image_html()` call
 * independently queried `wp_posts`/`wp_tube_video_metadata`, an N-query-
 * per-page pattern on exactly the pages that receive the bulk of this
 * project's real traffic. `_prime_post_caches()` is the same technique
 * this project's plugins already use for this (e.g.
 * `Tube_Seo\Sitemap\SitemapGenerator`); `tube_player_prime_video_metadata()`
 * is tube-player's own new equivalent for its own table (Phase 11).
 *
 * Purely a performance optimization — every caller already works
 * correctly without this, just with one query per card instead of two
 * queries for the whole grid.
 *
 * Phase 13: also primes `tube-core`'s actor/studio repositories
 * (`ActorRepository`/`StudioRepository`'s own request-lifetime caches —
 * see their docblocks) with every actor/studio ID referenced anywhere in
 * this grid, via one batched `tube_core_get_actors()`/`_get_studios()`
 * call each. `template-parts/video-card.php`'s own, per-card calls to
 * those same functions (for its "starring" badge) then hit an already-
 * warmed cache instead of issuing a new query — the identical priming
 * shape `tube_player_prime_video_metadata()` already established for
 * video metadata.
 *
 * @param SearchIndexRow[] $videos The videos about to be rendered.
 */
function tube_theme_prime_video_grid(array $videos): void
{
    if ([] === $videos) {
        return;
    }

    $video_ids = array_map(static fn (SearchIndexRow $video): int => $video->video_id, $videos);

    _prime_post_caches($video_ids, false, false);
    tube_player_prime_video_metadata($video_ids);

    $actor_ids  = [];
    $studio_ids = [];

    foreach ($videos as $video) {
        array_push($actor_ids, ...$video->actor_ids);
        array_push($studio_ids, ...$video->studio_ids);
    }

    if ([] !== $actor_ids) {
        tube_core_get_actors($actor_ids);
    }

    if ([] !== $studio_ids) {
        tube_core_get_studios($studio_ids);
    }
}

/**
 * Format a video's duration as `M:SS` (or `H:MM:SS` past an hour) for
 * the video card's duration badge, or `''` if unknown.
 *
 * @param int|null $seconds `SearchIndexRow::$duration_seconds`.
 */
function tube_theme_format_duration(?int $seconds): string
{
    if (null === $seconds || $seconds < 0) {
        return '';
    }

    $hours   = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs    = $seconds % 60;

    return $hours > 0
        ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs)
        : sprintf('%d:%02d', $minutes, $secs);
}

/**
 * The most-used `video_tag` terms, for the homepage "Tags Phổ Biến" block
 * and the discovery chip strip — real counts straight from WordPress's
 * own `wp_term_taxonomy.count` column (auto-maintained by
 * `wp_set_post_terms()` on every publish/unpublish transition), not a
 * computed `COUNT()` query. This is exactly the mechanism
 * `DEVELOPMENT_RULES.md`'s "reuse existing architecture" instruction
 * points at: `get_terms()` with `orderby => 'count'` costs the same as
 * any other `get_terms()` call, regardless of how many videos use a tag
 * or how many tags exist — safe at 100k+ daily visits with no new
 * caching layer.
 *
 * @param int $limit Maximum number of tags to return.
 *
 * @return WP_Term[] Highest video count first.
 */
function tube_theme_popular_tags(int $limit): array
{
    $terms = get_terms(
        [
            'taxonomy'   => 'video_tag',
            'hide_empty' => true,
            'orderby'    => 'count',
            'order'      => 'DESC',
            'number'     => $limit,
        ]
    );

    return is_array($terms) ? $terms : [];
}

/**
 * The deterministic tag-chip color class for one tag — the same tag
 * always renders in the same one of 8 hues everywhere it appears
 * (popular tags, single-video tags, the tags directory), never a
 * randomly-assigned color per page render. `$term_id % 8` is stable for
 * the life of the term (WordPress never reuses/reassigns a term's own
 * ID), so this needs no stored mapping — see `assets/css/tube-theme.css`'s
 * `.tag-chip--c0`..`.tag-chip--c7` for the actual color values.
 *
 * @param int $term_id The `video_tag` term ID.
 */
function tube_theme_tag_color_class(int $term_id): string
{
    return 'tag-chip--c' . ($term_id % 8);
}

/**
 * The deterministic discovery-chip color class for one CATEGORY term —
 * same idea and same `$term_id % 8` mechanism as
 * {@see tube_theme_tag_color_class()} above (a category keeps the same
 * hue everywhere it appears as a discovery chip, never randomized), but
 * its own `.discovery-chip--cat-c0`..`--c7` variants (2026-08-28) rather
 * than reusing `.tag-chip--cN` directly: discovery chips need three
 * visually distinct tiers (primary filter / category / tag), so
 * categories get the same 8-hue palette at their own "medium" opacity
 * tier, sitting between the primary trio's single strong accent and the
 * tag chips' subtler `.tag-chip--cN` treatment (reused as-is for
 * discovery tag chips, so a tag keeps one true color everywhere on the
 * site, not a second independent one just for this component).
 *
 * @param int $term_id The `video_category` term ID.
 */
function tube_theme_discovery_category_color_class(int $term_id): string
{
    return 'discovery-chip--cat-c' . ($term_id % 8);
}

/**
 * A discovery chip's emoji prefix + (optionally) a hand-picked gradient
 * class for one CATEGORY term, keyed by slug — curated for this site's
 * own known categories (2026-08-28 chip polish: two-tone gradients +
 * contextual emoji), never a name-matching heuristic that could
 * mismatch an unrelated category. A category not in this map still gets
 * a real, working chip: a generic 🎬 emoji and `color_class: null`,
 * which callers fall back to
 * {@see tube_theme_discovery_category_color_class()}'s own deterministic
 * `$term_id % 8` cyclical gradient for — so a brand-new category never
 * renders broken or uncolored, just less individually "designed" than
 * the ones explicitly curated here.
 *
 * @param string $slug The `video_category` term's slug.
 *
 * @return array{emoji: string, color_class: string|null}
 */
function tube_theme_discovery_category_meta(string $slug): array
{
    $known = [
        'phim-chau-a'   => [
            'emoji'       => '🌏',
            'color_class' => 'discovery-chip--cat-asia',
        ],
        'phim-chau-phi' => [
            'emoji'       => '🌍',
            'color_class' => 'discovery-chip--cat-africa',
        ],
        'phim-nhat'     => [
            'emoji'       => '🎌',
            'color_class' => 'discovery-chip--cat-japan',
        ],
        'phim-viet'     => [
            'emoji'       => '🇻🇳',
            'color_class' => 'discovery-chip--cat-vietnam',
        ],
        'phim-han'      => [
            'emoji'       => '🇰🇷',
            'color_class' => null,
        ],
        'phim-my'       => [
            'emoji'       => '🇺🇸',
            'color_class' => null,
        ],
    ];

    return $known[ $slug ] ?? [
        'emoji'       => '🎬',
        'color_class' => null,
    ];
}

/**
 * Compact display formatting for a real count (views, likes) — "999",
 * "1.2K", "18K", "1.2M" — display formatting only, never altering the
 * real underlying number anywhere it's stored/compared. Exists so a
 * view/like count with many digits can never be the reason a compact
 * control (the action bar, a video-card meta row) grows wide enough to
 * force layout overflow — the real fix for that class of bug is still
 * `flex-wrap`/`min-width: 0` where the control lives (see
 * `.video-actions`'s own CSS comment), this is what keeps the number
 * itself short in the first place.
 *
 * @param int $count The real count.
 */
function tube_theme_compact_number(int $count): string
{
    if ($count < 1000) {
        return number_format_i18n($count);
    }

    if ($count < 1000000) {
        $value = $count / 1000;

        return number_format_i18n($value, $value < 10 ? 1 : 0) . 'K';
    }

    $value = $count / 1000000;

    return number_format_i18n($value, $value < 10 ? 1 : 0) . 'M';
}

/**
 * Human-friendly relative publish time for the mobile watch page's meta
 * row (e.g. "12 phút trước", "2 giờ trước", "Hôm qua") — display
 * formatting only, computed fresh from `$published_at_gmt` on every call;
 * WordPress's own canonical `post_date`/`post_date_gmt` are never read
 * from or written to here.
 *
 * @param string $published_at_gmt `SearchIndexRow::$published_at` — a MySQL `DATETIME` string in UTC
 *                                  (`WP_Post::$post_date_gmt`, per `VideoIndexer`), or `''` if unknown.
 */
function tube_theme_relative_time(string $published_at_gmt): string
{
    if ('' === $published_at_gmt) {
        return '';
    }

    $timestamp = strtotime($published_at_gmt . ' +00:00');

    if (false === $timestamp) {
        return '';
    }

    $diff_seconds = time() - $timestamp;

    if ($diff_seconds < 60) {
        return __('Vừa xong', 'tube-theme');
    }

    if ($diff_seconds < HOUR_IN_SECONDS) {
        return sprintf(
            /* translators: %d: number of minutes. */
            __('%d phút trước', 'tube-theme'),
            intdiv($diff_seconds, MINUTE_IN_SECONDS)
        );
    }

    if ($diff_seconds < DAY_IN_SECONDS) {
        return sprintf(
            /* translators: %d: number of hours. */
            __('%d giờ trước', 'tube-theme'),
            intdiv($diff_seconds, HOUR_IN_SECONDS)
        );
    }

    if ($diff_seconds < 2 * DAY_IN_SECONDS) {
        return __('Hôm qua', 'tube-theme');
    }

    if ($diff_seconds < 30 * DAY_IN_SECONDS) {
        return sprintf(
            /* translators: %d: number of days. */
            __('%d ngày trước', 'tube-theme'),
            intdiv($diff_seconds, DAY_IN_SECONDS)
        );
    }

    // Older than 30 days: a real (site-timezone) date, numeric-only so it
    // never depends on a loaded locale's month-name translations.
    $formatted = wp_date('d/m/Y', $timestamp);

    return false === $formatted ? '' : $formatted;
}

/**
 * The current page number, from WordPress's own `paged` query var —
 * shared by every listing template so each one doesn't repeat the same
 * `is_numeric()` narrowing of `get_query_var()`'s untyped return.
 */
function tube_theme_current_page(): int
{
    $paged = get_query_var('paged');

    return is_numeric($paged) && (int) $paged > 0 ? (int) $paged : 1;
}

/**
 * The URL of the first published WordPress Page assigned a given page
 * template (e.g. `page-templates/trending.php`), or null if no such
 * Page exists yet.
 *
 * Trending/Most-Viewed/Latest (Phase 8) and the actor/studio directory
 * pages (Phase 13) are ordinary, editor-assigned WordPress Pages — per
 * `page-templates/trending.php`'s own docblock, this project's frozen
 * URL table (ARCHITECTURE.md §15.1) has no dedicated slug for them, so
 * their real URL is whatever an editor gives the Page they assign the
 * template to. The header/footer/mega-menu need to link to them without
 * hard-coding a slug.
 *
 * Every call (across header.php, footer.php — up to 5 distinct
 * templates per request) shares one bulk-resolved, request-lifetime map
 * (built by {@see tube_theme_resolve_page_template_urls()} on first
 * call) rather than each call issuing its own `get_posts()` query — this
 * runs on every single page load site-wide, so it's worth one query
 * instead of up to five.
 *
 * @param string $template The page template file, relative to the theme root (e.g. `page-templates/trending.php`).
 *
 * @return string|null The Page's permalink, or null if no published Page uses this template.
 */
function tube_theme_page_template_url(string $template): ?string
{
    /** @var array<string, string>|null $map */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
    static $map = null;

    if (null === $map) {
        $map = tube_theme_resolve_page_template_urls();
    }

    return $map[ $template ] ?? null;
}

/**
 * Resolve every published Page's assigned template into a
 * `template => permalink` map, in one query — the collaborator behind
 * {@see tube_theme_page_template_url()}.
 *
 * Native `get_posts()` against `wp_postmeta` (the same lookup WordPress
 * core's own page-template admin UI performs), not a dedicated-table
 * query this project's `$wpdb` rule (ARCHITECTURE.md §2.5) applies to.
 * `meta_compare => 'EXISTS'` (no `meta_value`) intentionally fetches
 * every custom-templated Page in one pass rather than one query per
 * template name — a real site has a small, bounded number of Pages with
 * a non-default template assigned, so this stays cheap regardless of
 * how many templates a caller ends up asking for.
 *
 * @return array<string, string> Template file => permalink. A template with no matching Page is simply absent.
 */
function tube_theme_resolve_page_template_urls(): array
{
    $pages = get_posts(
        [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_key'       => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_compare'   => 'EXISTS',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]
    );

    $map = [];

    foreach ($pages as $page) {
        $template = get_page_template_slug($page);

        if (!is_string($template) || '' === $template || isset($map[ $template ])) {
            continue;
        }

        $map[ $template ] = get_permalink($page);
    }

    return $map;
}

/**
 * Echo one footer social icon as inline SVG — no icon-font/library
 * dependency for four icons that never change. `esc_html()` isn't used
 * (these are fixed, hand-authored markup strings with no user input in
 * them, the same posture `tube_seo_head()` takes for its own static
 * inline markup), but the *key* is validated against an explicit
 * allow-list first so a caller can never echo arbitrary markup through
 * this function.
 *
 * @param string $key One of `facebook`, `telegram`, `twitter`, `youtube`.
 */
function tube_theme_social_icon(string $key): void
{
    $icons = [
        'facebook' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.51 1.49-3.9 3.77-3.9 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56v1.87h2.78l-.44 2.91h-2.34V22c4.78-.76 8.44-4.92 8.44-9.94Z"/></svg>',
        'telegram' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M21.9 4.6 18.6 20.3c-.25 1.1-.9 1.38-1.83.86l-5.06-3.73-2.44 2.35c-.27.27-.5.5-1.02.5l.36-5.16 9.4-8.5c.41-.36-.09-.56-.63-.2L6.2 13.1l-5.02-1.57c-1.1-.34-1.11-1.1.23-1.63L20.5 3.2c.9-.34 1.7.2 1.4 1.4Z"/></svg>',
        'twitter'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M13.9 10.4 21 2h-2.2l-6.16 7.1L7.7 2H2l7.45 10.7L2.3 22h2.2l6.5-7.5L16.3 22H22l-8.1-11.6ZM11.4 13.3l-.75-1.07L4.7 3.6h2.6l4.85 6.94.75 1.07 6.3 9h-2.6l-5.2-7.3Z"/></svg>',
        'youtube'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M23.5 7.2s-.23-1.64-.94-2.36c-.9-.95-1.9-.95-2.36-1.01C16.9 3.5 12 3.5 12 3.5h-.01s-4.9 0-8.2.33c-.46.06-1.46.06-2.36 1.01-.71.72-.94 2.36-.94 2.36S0 9.14 0 11.08v1.8c0 1.94.23 3.88.23 3.88s.23 1.64.94 2.36c.9.95 2.08.92 2.6 1.02 1.9.18 8.06.34 8.23.34.01 0 4.9 0 8.2-.33.46-.06 1.46-.06 2.36-1.02.71-.72.94-2.36.94-2.36s.23-1.94.23-3.88v-1.8c0-1.94-.23-3.88-.23-3.88ZM9.55 15.4V7.98l6.4 3.72-6.4 3.7Z"/></svg>',
    ];

    if (isset($icons[ $key ])) {
        echo $icons[ $key ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed, hand-authored SVG markup gated by an explicit allow-listed $key; no user input reaches this string.
    }
}

/**
 * Echo the header brand slot — one renderer for both modes (2026-08-28),
 * not two duplicate header blocks. Reads `tube_theme_header_brand_mode`
 * (`text`/`logo`, {@see tube_theme_sanitize_header_brand_mode()}) and
 * WordPress's own native custom-logo theme_mod (`custom_logo`, set by
 * `add_theme_support('custom-logo')` + the core Site Identity "Logo"
 * control — never a bespoke raw-URL field). Falls back to the text
 * brand whenever logo mode is selected but no logo is set, the
 * attachment was deleted, or it isn't actually an image — this
 * function can never emit a broken `<img>`, a blank header, or an
 * empty link, only ever one of the two real states.
 *
 * Both modes render inside the *same* `.site-header__home` anchor
 * (unchanged — every existing mobile-row order/flex-shrink/sizing rule
 * in tube-theme.css still targets it) with an added `.site-brand`
 * class for the normalized selector set this feature introduces
 * (`.site-brand`, `.site-brand__text`, `.site-brand__logo`).
 */
function tube_theme_render_site_brand(): void
{
    $mode = get_theme_mod('tube_theme_header_brand_mode', 'text');
    $mode = is_string($mode) ? $mode : 'text';

    $logo_id = 'logo' === $mode ? get_theme_mod('custom_logo') : 0;
    $logo_id = is_numeric($logo_id) ? (int) $logo_id : 0;

    if ($logo_id > 0 && wp_attachment_is_image($logo_id)) {
        tube_theme_render_site_brand_logo($logo_id);

        return;
    }

    tube_theme_render_site_brand_text();
}

/**
 * The text-brand slot -- `get_bloginfo('name')` (never a hardcoded
 * site name), with the existing red accent dot preserved as its own
 * explicit `.site-brand__accent` class (was a bare `span` selector,
 * `.site-header__home span`, before this task -- narrowed on purpose
 * so a future span added anywhere else inside this link, such as the
 * `.site-brand__text` wrapper this same change introduces, can never
 * accidentally pick up the accent color too).
 */
function tube_theme_render_site_brand_text(): void
{
    ?>
    <a class="site-header__home site-brand" href="<?php echo esc_url(home_url('/')); ?>">
        <span class="site-brand__text"><?php bloginfo('name'); ?></span><span class="site-brand__accent">.</span>
    </a>
    <?php
}

/**
 * The logo-brand slot. `wp_get_attachment_image()` (a core media API,
 * not a hand-built `<img src="">`) so width/height/srcset come from
 * the attachment's own real metadata whenever available. Alt text
 * follows the exact same fallback WordPress core's own
 * `get_custom_logo()` uses internally: the attachment's own alt text
 * if set, else the site name -- so the link (whose only content is
 * this image) always has a real accessible name. No separate
 * `aria-label` on the anchor: with a single-image link, the image's
 * own `alt` already *is* the link's accessible name, and adding a
 * second one risks a screen reader announcing the name twice.
 *
 * @param int $logo_id Attachment ID, already verified to be a real image by the caller.
 */
function tube_theme_render_site_brand_logo(int $logo_id): void
{
    $alt = get_post_meta($logo_id, '_wp_attachment_image_alt', true);
    $alt = is_string($alt) && '' !== trim($alt) ? $alt : get_bloginfo('name');

    ?>
    <a class="site-header__home site-brand site-brand--logo" href="<?php echo esc_url(home_url('/')); ?>">
        <span class="site-brand__logo">
            <?php
            echo wp_get_attachment_image(
                $logo_id,
                'full',
                false,
                [
                    'alt'      => $alt,
                    'loading'  => 'eager',
                    'decoding' => 'async',
                ]
            );
            ?>
        </span>
    </a>
    <?php
}
