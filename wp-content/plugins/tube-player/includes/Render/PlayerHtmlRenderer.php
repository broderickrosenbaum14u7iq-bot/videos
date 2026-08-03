<?php
/**
 * Builds the click-to-load player block — `tube_player_get_embed_html()`.
 *
 * @package Tube_Player
 */

declare(strict_types=1);

namespace Tube_Player\Render;

use Tube_Player\Video\ImageSize;
use Tube_Player\Video\VideoProviderInterface;

/**
 * Builds the click-to-load player block behind `tube_player_get_embed_html()`
 * (ARCHITECTURE.md §12 Phase 6 — "click-to-load embed").
 *
 * The markup is entirely server-rendered, including the embed URL
 * itself (in `data-embed-url`) — the small client-side script
 * (`assets/js/tube-player.js`) only ever swaps the poster for an
 * `<iframe>` using that pre-computed attribute, never fetches anything
 * (this phase's "avoid unnecessary REST requests" instruction). The
 * outer wrapper reserves its aspect ratio via CSS `aspect-ratio` before
 * any image loads — zero CLS by construction, not by measurement.
 *
 * Keyboard access needs no dedicated handling: the play control is a
 * native `<button>`, which is Tab-focusable and fires `click` on both
 * Enter and Space natively. The `<noscript>` fallback is a real link to
 * the same embed URL, so a JS-disabled visitor can still watch the video
 * (this phase's "meaningful fallback" requirement).
 *
 * WordPress-coupled (`esc_url()`/`esc_attr()`/`esc_html()`/`__()`) —
 * verified via integration tests and live checks, the same split
 * `ImageHtmlRenderer` uses.
 */
final class PlayerHtmlRenderer
{
    /**
     * The aspect ratio reserved by the outer wrapper when `$args['aspect_ratio']` isn't given.
     */
    private const DEFAULT_ASPECT_RATIO = '16 / 9';

    /**
     * Construct around the collaborators this renderer composes.
     *
     * @param VideoProviderInterface $stream_provider Resolves the embed URL.
     * @param ImageHtmlRenderer      $image_renderer  Renders the poster `<img>` this block wraps.
     */
    public function __construct(
        private readonly VideoProviderInterface $stream_provider,
        private readonly ImageHtmlRenderer $image_renderer
    ) {
    }

    /**
     * Render one click-to-load player block.
     *
     * @param string                     $cf_stream_uid Cloudflare Stream UID.
     * @param int                        $thumbnail_time_seconds Default-thumbnail extraction offset, in seconds.
     * @param int|null                   $override_poster_image_id Cloudflare Images ID overriding the default, if set.
     * @param array<string, bool|string> $args `title`/`aspect_ratio`/`class` (string), `eager` (bool). All optional.
     */
    public function render(
        string $cf_stream_uid,
        int $thumbnail_time_seconds,
        ?int $override_poster_image_id,
        array $args = []
    ): string {
        $embed_url    = $this->stream_provider->embed_url($cf_stream_uid);
        $title        = self::string_arg($args, 'title', '');
        $eager        = self::bool_arg($args, 'eager', false);
        $aspect_ratio = self::string_arg($args, 'aspect_ratio', self::DEFAULT_ASPECT_RATIO);
        $class        = trim('tube-player ' . self::string_arg($args, 'class', ''));

        $poster_html = $this->image_renderer->render(
            $cf_stream_uid,
            $thumbnail_time_seconds,
            $override_poster_image_id,
            ImageSize::Hero,
            [
                'eager'         => $eager,
                'fetchpriority' => $eager ? 'high' : 'auto',
                'alt'           => $title,
            ]
        );

        return sprintf(
            '<div class="%1$s" style="aspect-ratio:%2$s" data-tube-player data-embed-url="%3$s" data-title="%7$s">'
                . '%4$s'
                . '<button type="button" class="tube-player__play" aria-label="%5$s">'
                . '<svg class="tube-player__play-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
                . '<path d="M8 5v14l11-7z"></path></svg>'
                . '</button>'
                . '<noscript><a href="%3$s" class="tube-player__noscript-link">%6$s</a></noscript>'
                . '</div>',
            esc_attr($class),
            esc_attr($aspect_ratio),
            esc_url($embed_url),
            $poster_html,
            esc_attr($this->play_label($title)),
            esc_html($this->watch_label($title)),
            esc_attr('' === $title ? __('Video player', 'tube-player') : $title)
        );
    }

    /**
     * The play button's aria-label.
     *
     * @param string $title The video's title, or '' if none was given.
     */
    private function play_label(string $title): string
    {
        if ('' === $title) {
            return __('Play video', 'tube-player');
        }

        // translators: %s is the video's title.
        return sprintf(__('Play video: %s', 'tube-player'), $title);
    }

    /**
     * The `<noscript>` fallback link's text.
     *
     * @param string $title The video's title, or '' if none was given.
     */
    private function watch_label(string $title): string
    {
        if ('' === $title) {
            return __('Watch video', 'tube-player');
        }

        // translators: %s is the video's title.
        return sprintf(__('Watch video: %s', 'tube-player'), $title);
    }

    /**
     * Read a string option from $args, genuinely checked rather than
     * blindly cast — see `ImageHtmlRenderer::string_arg()`, duplicated
     * here (not shared) since it's three lines and each class stays
     * self-contained rather than reaching into the other's internals.
     *
     * @param array<string, bool|string> $args    This renderer's $args.
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
     * @param array<string, bool|string> $args    This renderer's $args.
     * @param string                     $key     Which option to read.
     * @param bool                       $fallback The value to use if $key is missing or not a bool.
     */
    private static function bool_arg(array $args, string $key, bool $fallback): bool
    {
        return isset($args[ $key ]) && is_bool($args[ $key ]) ? $args[ $key ] : $fallback;
    }
}
