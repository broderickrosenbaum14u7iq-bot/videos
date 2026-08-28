<?php
/**
 * Builds the poster/thumbnail `<img>` tag — `tube_player_get_image_html()`.
 *
 * @package Tube_Player
 */

declare(strict_types=1);

namespace Tube_Player\Render;

use Tube_Player\Video\ImageSize;

/**
 * Builds the poster/thumbnail `<img>` tag behind `tube_player_get_image_html()`
 * (ARCHITECTURE.md §5/§8) — explicit `width`/`height` (zero CLS), a
 * `srcset` of WordPress's own attachment-derivative candidates, and
 * `loading`/`fetchpriority` controlled by the caller, since only the
 * theme (Phase 8) knows whether a given instance is above the fold
 * (ARCHITECTURE.md §5 — the theme stays presentation-only, this class
 * stays the one place the actual markup is built).
 *
 * The WordPress Media Library poster/OG-image attachment is the only
 * image source (ADR-0001, refined by its 2026-08-25 addendum): there is
 * no Cloudflare Stream thumbnail default/fallback anywhere in this
 * class. A video with no attachment set (or a stale/deleted attachment
 * ID) renders no `<img>` at all — `self::render()` returns `''`, and the
 * theme's own container styling (a neutral background, per
 * `ImageSize`'s aspect-ratio box) is the empty state, not a
 * Cloudflare-generated image.
 *
 * A plain `<img src>` (no `<picture>`/`<source>`) is deliberate, not a
 * missing feature — WordPress's own attachment-derivative `srcset`
 * already covers responsive delivery without a `<picture>` element.
 *
 * WordPress-coupled (`esc_url()`/`esc_attr()`/`wp_get_attachment_image_url()`/
 * `wp_get_attachment_image_srcset()`) — verified via integration tests
 * and live checks, not unit-tested, the same split this project applies
 * to every thin real-output adapter.
 */
final class ImageHtmlRenderer
{
    /**
     * Render one `<img>` tag.
     *
     * @param int|null                   $override_image_id WP attachment ID to render (ADR-0001).
     * @param ImageSize                  $size Which size preset to render.
     * @param array<string, bool|string> $args `eager` (bool), `fetchpriority`/`alt`/`class` (string). All optional.
     *
     * @return string The `<img>` tag, or '' if `$override_image_id` is null or doesn't resolve to a real attachment.
     */
    public function render(
        ?int $override_image_id,
        ImageSize $size,
        array $args = []
    ): string {
        $urls = $this->resolve_urls($override_image_id, $size);

        if (null === $urls['src']) {
            return '';
        }

        $eager         = self::bool_arg($args, 'eager', false);
        $loading       = $eager ? 'eager' : 'lazy';
        $fetchpriority = self::string_arg($args, 'fetchpriority', $eager ? 'high' : 'auto');
        $alt           = self::string_arg($args, 'alt', '');
        $class         = trim('tube-player__poster ' . self::string_arg($args, 'class', ''));

        $srcset_attr = null === $urls['srcset'] ? '' : sprintf(' srcset="%s"', esc_attr($urls['srcset']));

        return sprintf(
            '<img src="%1$s"%2$s width="%3$d" height="%4$d" alt="%5$s" loading="%6$s" fetchpriority="%7$s"'
                . ' decoding="async" class="%8$s" />',
            esc_url($urls['src']),
            $srcset_attr,
            $size->width(),
            $size->height(),
            esc_attr($alt),
            esc_attr($loading),
            esc_attr($fetchpriority),
            esc_attr($class)
        );
    }

    /**
     * Resolve the `src` and, when available, the `srcset` for one image —
     * WordPress Media Library only (ADR-0001, refined by its 2026-08-25
     * addendum).
     *
     * Public so `tube-seo`'s `SeoHead`/`SitemapGenerator`/`VideoObjectBuilder`
     * callers (which need a bare URL, not a full `<img>` tag, for a
     * `<meta>` tag/JSON-LD/XML sitemap entry) can resolve the same image
     * this renderer's own `render()` uses, instead of each hand-rolling
     * its own copy of the resolution logic.
     *
     * @param int|null  $override_image_id A WordPress attachment ID, or null (ADR-0001).
     * @param ImageSize $size              Which size preset to resolve.
     *
     * @return array{src: string|null, srcset: string|null} `src` is null when `$override_image_id` is null or
     *     doesn't resolve to a real attachment (e.g. it was deleted) — there is no other image source to fall
     *     back to.
     */
    public function resolve_urls(?int $override_image_id, ImageSize $size): array
    {
        if (null === $override_image_id) {
            return [
                'src'    => null,
                'srcset' => null,
            ];
        }

        $attachment_src = wp_get_attachment_image_url($override_image_id, [$size->width(), $size->height()]);

        if (false === $attachment_src) {
            return [
                'src'    => null,
                'srcset' => null,
            ];
        }

        $srcset = wp_get_attachment_image_srcset($override_image_id, [$size->width(), $size->height()]);

        return [
            'src'    => $attachment_src,
            'srcset' => is_string($srcset) ? $srcset : null,
        ];
    }

    /**
     * Read a string option from $args, genuinely checked rather than
     * blindly cast — $args is `array<string, bool|string>`, so a wrong
     * key or wrong-typed value falls back to $fallback instead of
     * producing a type error at the `esc_attr()`/`esc_url()` call sites.
     *
     * @param array<string, bool|string> $args    The renderer's $args.
     * @param string                     $key     Which option to read.
     * @param string                     $fallback The value to use if $key is missing or not a string.
     */
    private static function string_arg(array $args, string $key, string $fallback): string
    {
        return isset($args[ $key ]) && is_string($args[ $key ]) ? $args[ $key ] : $fallback;
    }

    /**
     * Read a bool option from $args — see self::string_arg() for why this is checked, not cast.
     *
     * @param array<string, bool|string> $args    The renderer's $args.
     * @param string                     $key     Which option to read.
     * @param bool                       $fallback The value to use if $key is missing or not a bool.
     */
    private static function bool_arg(array $args, string $key, bool $fallback): bool
    {
        return isset($args[ $key ]) && is_bool($args[ $key ]) ? $args[ $key ] : $fallback;
    }
}
