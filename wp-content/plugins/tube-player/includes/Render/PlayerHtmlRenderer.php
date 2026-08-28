<?php
/**
 * Builds the click-to-load player block — `tube_player_get_embed_html()`.
 *
 * @package Tube_Player
 */

declare(strict_types=1);

namespace Tube_Player\Render;

use Tube_Core\Video\CfStreamStatus;
use Tube_Player\Video\ImageSize;
use Tube_Player\Video\VideoProviderInterface;

/**
 * Builds the click-to-load player block behind `tube_player_get_embed_html()`
 * (ARCHITECTURE.md §12 Phase 6 — "click-to-load embed").
 *
 * The markup is entirely server-rendered, including the embed URL
 * itself (in `data-embed-url`) — the small client-side script
 * (`assets/js/tube-player.js`) swaps the poster for an `<iframe>` using
 * that pre-computed attribute; no URL-construction logic lives in that
 * script for either purpose. Since 2026-08-25 it also fires one
 * fire-and-forget `POST` to `data-view-url` (also pre-built here, never
 * assembled client-side) the first time a real visitor activates the
 * player — the view-recording call site `Tube_Core\Views\ViewController`'s
 * own docblock describes as long "deferred." The outer wrapper reserves
 * its aspect ratio via CSS `aspect-ratio` before any image loads — zero
 * CLS by construction, not by measurement.
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
     * 2026-08-28 (P0 HIGH-3 fix): the interactive click-to-load markup
     * below (`data-tube-player`/`data-embed-url`/the `.tube-player__play`
     * button) is now only ever rendered when `$status` is
     * `CfStreamStatus::Ready`. Every other status renders through
     * {@see self::render_status_overlay()} instead -- no `data-tube-player`
     * attribute, no `.tube-player__play` button, no `data-embed-url`/
     * `data-view-url` -- which is deliberately what makes this safe with
     * zero changes needed to `assets/js/tube-player.js` or
     * `tube-ads`' own preroll script: both are wired entirely off a real
     * click on `.tube-player__play` (`event.target.closest('.tube-player__play')`,
     * then `.closest('[data-tube-player]')`) -- remove that one element
     * and player activation, VAST/preroll, and the view-increment POST
     * (whose URL only ever exists as that same button's sibling
     * attribute) all become unreachable at once, not three separate
     * fixes to keep in sync.
     *
     * @param int                        $video_id The video post ID — embedded only as `data-view-url`
     *     (a pre-built, server-rendered REST URL), the same "no URL-construction logic in client-side JS"
     *     posture `data-embed-url` already established; never sent anywhere else by this class.
     * @param string                     $cf_stream_uid Cloudflare Stream UID.
     * @param CfStreamStatus             $status   The video's current Cloudflare Stream processing status —
     *     stored/synchronized metadata (`StreamStatusUpdater`), never a live per-render Cloudflare API call.
     * @param int|null                   $override_poster_image_id WP attachment ID to use as the poster (ADR-0001).
     * @param array<string, bool|string> $args `title`/`aspect_ratio`/`class` (string), `eager` (bool). All optional.
     */
    public function render(
        int $video_id,
        string $cf_stream_uid,
        CfStreamStatus $status,
        ?int $override_poster_image_id,
        array $args = []
    ): string {
        if (CfStreamStatus::Ready !== $status) {
            return $this->render_status_overlay($this->status_message($status), $override_poster_image_id, $args);
        }

        $embed_url    = $this->stream_provider->embed_url($cf_stream_uid);
        $view_url     = rest_url('tube/v1/videos/' . $video_id . '/view');
        $title        = self::string_arg($args, 'title', '');
        $eager        = self::bool_arg($args, 'eager', false);
        $aspect_ratio = self::string_arg($args, 'aspect_ratio', self::DEFAULT_ASPECT_RATIO);
        $class        = trim('tube-player ' . self::string_arg($args, 'class', ''));

        $poster_html = $this->image_renderer->render(
            $override_poster_image_id,
            ImageSize::Hero,
            [
                'eager'         => $eager,
                'fetchpriority' => $eager ? 'high' : 'auto',
                'alt'           => $title,
            ]
        );

        return sprintf(
            '<div class="%1$s" style="aspect-ratio:%2$s" data-tube-player data-embed-url="%3$s"'
                . ' data-view-url="%8$s" data-title="%7$s">'
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
            esc_attr('' === $title ? __('Video player', 'tube-player') : $title),
            esc_url($view_url)
        );
    }

    /**
     * Render the non-interactive block for a video with no stored
     * metadata row at all (2026-08-28, P0 HIGH-2 fix) --
     * `tube_player_get_embed_html()` used to return `''` here, which
     * `single-video.php` had no fallback for, producing a silent,
     * unexplained gap where the player should be (no message, no
     * reserved height, `.video-player-wrap` collapsed to zero). Same
     * non-interactive wrapper `self::render()` itself now uses for a
     * non-Ready status, since "no metadata" and "not ready yet" are the
     * same user-facing situation: nothing playable exists right now.
     *
     * @param array<string, bool|string> $args `title`/`aspect_ratio`/`class` (string). All optional.
     */
    public function render_missing(array $args = []): string
    {
        return $this->render_status_overlay(
            __('Video hiện chưa sẵn sàng.', 'tube-player'),
            null,
            $args
        );
    }

    /**
     * The Vietnamese message shown for each non-Ready status — see
     * `self::render()`'s own docblock for why this branch exists at all.
     *
     * @param CfStreamStatus $status Never `Ready` — that case never reaches this method.
     */
    private function status_message(CfStreamStatus $status): string
    {
        return match ($status) {
            CfStreamStatus::Pending, CfStreamStatus::Processing => __('Video đang được xử lý.', 'tube-player'),
            CfStreamStatus::Error => __('Video hiện không khả dụng.', 'tube-player'),
            CfStreamStatus::Ready => '', // Unreachable -- self::render() branches before calling this method for Ready.
        };
    }

    /**
     * The shared non-interactive block both `self::render()` (a non-Ready
     * status) and `self::render_missing()` (no metadata row at all)
     * render through — same outer wrapper class/`aspect-ratio` as the
     * real interactive player (stable layout, zero CLS either way,
     * `.video-player-wrap`/`.tube-player` never collapses), the poster
     * behind a dimmed overlay if one resolves (graceful degrade to no
     * image otherwise, the same as `ImageHtmlRenderer`'s own established
     * behavior), but deliberately no `data-tube-player`/`data-embed-url`/
     * `data-view-url`/`.tube-player__play` button -- see `self::render()`'s
     * own docblock for why omitting exactly that is what prevents
     * activation, VAST/preroll, and the view-increment call all at once.
     *
     * @param string                     $message                  Already-translated Vietnamese status text.
     * @param int|null                   $override_poster_image_id WP attachment ID to use as the poster, if any.
     * @param array<string, bool|string> $args                     `title`/`aspect_ratio`/`class` (string). All optional.
     */
    private function render_status_overlay(string $message, ?int $override_poster_image_id, array $args): string
    {
        $title        = self::string_arg($args, 'title', '');
        $aspect_ratio = self::string_arg($args, 'aspect_ratio', self::DEFAULT_ASPECT_RATIO);
        $class        = trim('tube-player tube-player--unavailable ' . self::string_arg($args, 'class', ''));

        $poster_html = $this->image_renderer->render(
            $override_poster_image_id,
            ImageSize::Hero,
            [
                'eager' => false,
                'alt'   => $title,
            ]
        );

        return sprintf(
            '<div class="%1$s" style="aspect-ratio:%2$s">'
                . '%3$s'
                . '<div class="tube-player__status">'
                . '<svg class="tube-player__status-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
                . '<path d="M12 6v6l4 2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>'
                . '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"></circle>'
                . '</svg>'
                . '<p class="tube-player__status-message">%4$s</p>'
                . '</div>'
                . '</div>',
            esc_attr($class),
            esc_attr($aspect_ratio),
            $poster_html,
            esc_html($message)
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
