<?php
/**
 * Tube-player's theme-facing template tags (ARCHITECTURE.md §5).
 *
 * Global functions, not class methods — this is the one part of
 * tube-player's public surface a theme (Phase 8) actually calls, so it
 * follows WordPress's own template-tag convention rather than requiring
 * theme code to know any internal namespace. Everything here is a thin
 * wrapper: look up the video's stored metadata (never a playback URL —
 * ARCHITECTURE.md §2.1), then delegate to `Tube_Player\Plugin`'s
 * renderers. This is the one place tube-player reads
 * `Tube_Core\Video\VideoMetadata` — a real, direct type reference,
 * intentional here (unlike the pure/unit-tested classes elsewhere in
 * this plugin) because `Tube_Player` declares `Requires Plugins:
 * tube-core`, so WordPress guarantees tube-core is loaded first; this
 * file is verified live/via integration tests, not run by tube-player's
 * own WordPress-independent unit suite.
 *
 * No `ABSPATH` guard here — `tube-player.php` already exits before
 * `require_once`-ing this file, so a second check here would be dead
 * code (and would trip PSR1's "no side effects alongside symbol
 * declarations" rule, since this file only ever declares functions).
 *
 * @package Tube_Player
 */

declare(strict_types=1);

use Tube_Core\Plugin as Tube_Core_Plugin;
use Tube_Core\Video\VideoSource;
use Tube_Player\Video\ImageSize;

/**
 * Batch-fetch video metadata for a list of video IDs, warming
 * `Tube_Core\Video\Repositories\VideoMetadataRepository`'s own
 * request-lifetime cache so that a subsequent per-video
 * `tube_player_get_image_html()`/`tube_player_get_embed_html()` call for
 * any of these IDs is a free in-memory lookup instead of its own query.
 *
 * Added in Phase 11: every theme grid template (homepage, archive/tag/
 * category/actor/studio listings, search, related videos) renders one
 * `template-parts/video-card` per item, and each card independently
 * called `tube_player_get_image_html()` — one `wp_tube_video_metadata`
 * query per card, on exactly the highest-traffic pages. Call this once
 * with every video ID in the grid, before the loop that renders them.
 *
 * Purely a performance optimization — correctness never depends on
 * calling this first; every template tag it feeds still works standalone
 * (just with its own per-video query) if this is skipped.
 *
 * @param int[] $video_ids The video post IDs about to be rendered.
 */
function tube_player_prime_video_metadata(array $video_ids): void
{
    if ([] === $video_ids) {
        return;
    }

    Tube_Core_Plugin::instance()->video_metadata_repository()->find_many($video_ids);
}

/**
 * Render a video's poster/thumbnail `<img>` tag.
 *
 * @param int                        $video_id The video post ID.
 * @param string                     $size     `ImageSize` value: `grid_card`, `hero`, or `og_image`.
 * @param array<string, bool|string> $args     See `ImageHtmlRenderer::render()`. All optional.
 *
 * @return string The `<img>` tag, or '' if the size is unrecognized or the video has no stored metadata.
 */
function tube_player_get_image_html(int $video_id, string $size, array $args = []): string
{
    $resolved_size = ImageSize::tryFrom($size);

    if (null === $resolved_size) {
        return '';
    }

    $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id);

    if (null === $metadata) {
        return '';
    }

    wp_enqueue_style(
        'tube-player',
        plugins_url('assets/css/tube-player.css', TUBE_PLAYER_FILE),
        [],
        TUBE_PLAYER_VERSION
    );

    $override_image_id = ImageSize::OgImage === $resolved_size ? $metadata->og_image_id : $metadata->poster_image_id;

    return \Tube_Player\Plugin::instance()->image_renderer()->render(
        $override_image_id,
        $resolved_size,
        $args
    );
}

/**
 * Render a video's click-to-load player block.
 *
 * 2026-08-28 (P0 HIGH-2 fix): this used to return `''` when the video
 * had no stored metadata row at all, which `single-video.php` had no
 * fallback for — a silent, unexplained gap where the player should be
 * (no message, `.video-player-wrap` collapsed to zero height, no
 * console error to even hint something was wrong). Now always returns
 * real markup: the interactive click-to-load block only when metadata
 * exists AND its Cloudflare Stream status is `Ready`
 * ({@see \Tube_Player\Render\PlayerHtmlRenderer::render()}), a
 * non-interactive status overlay otherwise (missing metadata, or a
 * real but not-yet-Ready status — pending/processing/error).
 *
 * @param int                        $video_id The video post ID.
 * @param array<string, bool|string> $args     See `PlayerHtmlRenderer::render()`. All optional.
 *
 * @return string The player block's HTML — never ''.
 */
function tube_player_get_embed_html(int $video_id, array $args = []): string
{
    wp_enqueue_style(
        'tube-player',
        plugins_url('assets/css/tube-player.css', TUBE_PLAYER_FILE),
        [],
        TUBE_PLAYER_VERSION
    );

    $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id);

    if (null === $metadata) {
        return \Tube_Player\Plugin::instance()->player_renderer()->render_missing($args);
    }

    wp_enqueue_script(
        'tube-player',
        plugins_url('assets/js/tube-player.js', TUBE_PLAYER_FILE),
        [],
        TUBE_PLAYER_VERSION,
        true
    );

    $r2_playback_url = VideoSource::R2Mp4 === $metadata->source && null !== $metadata->r2_object_key
        ? Tube_Core_Plugin::instance()->r2_playback_url_signer()->sign_url($metadata->r2_object_key)
        : null;

    return \Tube_Player\Plugin::instance()->player_renderer()->render(
        $video_id,
        $metadata->source,
        $metadata->cf_stream_uid,
        $r2_playback_url,
        $metadata->cf_status,
        $metadata->poster_image_id,
        $args
    );
}

/**
 * The URL SEO (`Tube_Seo\Head\SeoHead`'s JSON-LD `embedUrl`) and the
 * sitemap (`Tube_Seo\Sitemap\SitemapGenerator`'s `<video:player_loc>`)
 * should reference for one video, regardless of source.
 *
 * For Cloudflare Stream this is the real click-to-load iframe embed URL
 * — a stable, long-lived, publicly fetchable URL, safe for persistent/
 * cached structured data.
 *
 * For R2/direct-MP4 this is deliberately the video's own watch-page
 * permalink, NOT the underlying media file — since the R2 bucket is
 * private behind a Cloudflare Worker
 * (`infrastructure/cloudflare/r2-media-worker/`), the only fetchable MP4
 * URL is a signed one that expires in
 * `Tube_Core\Video\R2\R2PlaybackUrlSigner::DEFAULT_TTL_SECONDS`, and
 * schema.org's `embedUrl`/Google's `player_loc` both permit "a page that
 * embeds the content," not only the raw media file — putting a
 * 10-minute-lived signed URL into persistent/cached structured data
 * would go stale almost immediately and isn't required by either spec.
 *
 * @param int $video_id The video post ID.
 *
 * @return string The resolved URL, or '' if the video has no metadata row or no resolvable identifier.
 */
function tube_player_get_source_url(int $video_id): string
{
    $metadata = Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id);

    if (null === $metadata) {
        return '';
    }

    return match ($metadata->source) {
        VideoSource::CloudflareStream => null === $metadata->cf_stream_uid
            ? ''
            : \Tube_Player\Plugin::instance()->video_provider()->embed_url($metadata->cf_stream_uid),
        VideoSource::R2Mp4 => null === $metadata->r2_object_key
            ? ''
            : (string) get_permalink($video_id),
    };
}

/**
 * Render an actor/studio photo's `<img>` tag (Phase 13) — for
 * `Tube_Core\Content\Actor::$photo_image_id`/`Studio::$logo_image_id`,
 * never a video (see `ProfileImageHtmlRenderer`'s docblock for why this
 * is a separate template tag from `tube_player_get_image_html()`, not an
 * overload of it). No stylesheet is enqueued here — unlike
 * `tube_player_get_image_html()`/`tube_player_get_embed_html()`,
 * nothing in `assets/css/tube-player.css` targets this markup; sizing/
 * shape (e.g. a circular crop) is theme-layer presentation.
 *
 * @param int|null                   $image_id A Cloudflare Images ID, or null for no photo.
 * @param string                     $size     `ImageSize` value; defaults to `avatar`.
 * @param array<string, bool|string> $args     See `ProfileImageHtmlRenderer::render()`. All optional.
 *
 * @return string The `<img>` tag, or '' if the size is unrecognized, `$image_id` is null, or Cloudflare Images
 *                isn't configured.
 */
function tube_player_get_profile_image_html(?int $image_id, string $size = 'avatar', array $args = []): string
{
    $resolved_size = ImageSize::tryFrom($size);

    if (null === $resolved_size) {
        return '';
    }

    return \Tube_Player\Plugin::instance()->profile_image_renderer()->render($image_id, $resolved_size, $args);
}
