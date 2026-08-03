<?php
/**
 * Builds the poster/thumbnail `<img>` tag — `tube_player_get_image_html()`.
 *
 * @package Tube_Player
 */

declare(strict_types=1);

namespace Tube_Player\Render;

use Tube_Player\Video\Cloudflare\CloudflareImagesUrlBuilder;
use Tube_Player\Video\ImageSize;
use Tube_Player\Video\VideoProviderInterface;

/**
 * Builds the poster/thumbnail `<img>` tag behind `tube_player_get_image_html()`
 * (ARCHITECTURE.md §5/§8) — explicit `width`/`height` (zero CLS), a 1x/2x
 * `srcset` from Cloudflare's own resizing (no local intermediate sizes,
 * per §8), and `loading`/`fetchpriority` controlled by the caller, since
 * only the theme (Phase 8) knows whether a given instance is above the
 * fold (ARCHITECTURE.md §5 — the theme stays presentation-only, this
 * class stays the one place the actual markup is built).
 *
 * A plain `<img src>` (no `<picture>`/`<source>`) is deliberate, not a
 * missing feature: Cloudflare's `format=auto` content negotiation
 * already serves WebP/AVIF via the `Accept` header on a single URL, so a
 * `<picture>` element would only add markup without adding capability
 * (§8's "no format conversion... logic lives in WordPress at all").
 *
 * WordPress-coupled (`esc_url()`/`esc_attr()`) — verified via integration
 * tests and live checks, not unit-tested, the same split this project
 * applies to every thin real-output adapter.
 */
final class ImageHtmlRenderer
{
    /**
     * Construct around the collaborators that resolve an image's URL(s).
     *
     * @param VideoProviderInterface          $stream_provider    The default (Cloudflare Stream thumbnail) source.
     * @param CloudflareImagesUrlBuilder|null $images_url_builder The poster-override source, or null if not configured.
     */
    public function __construct(
        private readonly VideoProviderInterface $stream_provider,
        private readonly ?CloudflareImagesUrlBuilder $images_url_builder
    ) {
    }

    /**
     * Render one `<img>` tag.
     *
     * @param string                     $cf_stream_uid Cloudflare Stream UID.
     * @param int                        $thumbnail_time_seconds Default-thumbnail extraction offset, in seconds.
     * @param int|null                   $override_image_id Cloudflare Images ID overriding the default, if set.
     * @param ImageSize                  $size Which size preset to render.
     * @param array<string, bool|string> $args `eager` (bool), `fetchpriority`/`alt`/`class` (string). All optional.
     */
    public function render(
        string $cf_stream_uid,
        int $thumbnail_time_seconds,
        ?int $override_image_id,
        ImageSize $size,
        array $args = []
    ): string {
        $urls = $this->resolve_urls($cf_stream_uid, $thumbnail_time_seconds, $override_image_id, $size);

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
     * Resolve the `src` and, when available, the `srcset` for one image.
     *
     * @param string    $cf_stream_uid          The Cloudflare Stream UID.
     * @param int       $thumbnail_time_seconds Default-thumbnail extraction offset, in seconds.
     * @param int|null  $override_image_id      A Cloudflare Images ID overriding the default, if set.
     * @param ImageSize $size                   Which size preset to render.
     *
     * @return array{src: string, srcset: string|null}
     */
    private function resolve_urls(
        string $cf_stream_uid,
        int $thumbnail_time_seconds,
        ?int $override_image_id,
        ImageSize $size
    ): array {
        if (null !== $override_image_id && null !== $this->images_url_builder) {
            // A Cloudflare Images variant is a fixed, pre-configured
            // size — there is no client-selectable 2x request the way
            // Stream's thumbnail endpoint offers, so no srcset here.
            return [
                'src'    => $this->images_url_builder->url($override_image_id, $size->value),
                'srcset' => null,
            ];
        }

        $src    = $this->stream_provider->thumbnail_url(
            $cf_stream_uid,
            $thumbnail_time_seconds,
            $size->width(),
            $size->height()
        );
        $src_2x = $this->stream_provider->thumbnail_url(
            $cf_stream_uid,
            $thumbnail_time_seconds,
            $size->width() * 2,
            $size->height() * 2
        );

        return [
            'src'    => $src,
            'srcset' => "{$src} 1x, {$src_2x} 2x",
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
