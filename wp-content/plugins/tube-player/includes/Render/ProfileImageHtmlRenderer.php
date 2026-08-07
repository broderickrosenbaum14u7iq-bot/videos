<?php
/**
 * Builds the actor/studio photo `<img>` tag — `tube_player_get_profile_image_html()`.
 *
 * @package Tube_Player
 */

declare(strict_types=1);

namespace Tube_Player\Render;

use Tube_Player\Video\Cloudflare\CloudflareImagesUrlBuilder;
use Tube_Player\Video\ImageSize;

/**
 * Builds the `<img>` tag for an actor/studio photo (Phase 13) — a sibling
 * to `ImageHtmlRenderer`, not a variant of it, because an actor/studio
 * photo has no Cloudflare Stream UID to fall back to: `Actor::$photo_image_id`/
 * `Studio::$logo_image_id` are always either a real Cloudflare Images ID
 * or null (no photo at all), never a video-style "default thumbnail,
 * optionally overridden" pair. Reuses only `CloudflareImagesUrlBuilder`
 * (the collaborator `ImageHtmlRenderer::resolve_urls()` already uses for
 * its own override path) — the Stream-thumbnail branch that class also
 * has is simply not applicable here, so it isn't reused.
 *
 * No `srcset`, for the same reason `ImageHtmlRenderer::resolve_urls()`
 * has none for its own Cloudflare Images override path: a Cloudflare
 * Images variant is a fixed, pre-configured size, with no client-
 * selectable 2x request the way Stream's thumbnail endpoint offers.
 *
 * WordPress-coupled (`esc_url()`/`esc_attr()`) — same split every thin
 * real-output adapter in this project uses (see `ImageHtmlRenderer`'s
 * own docblock).
 */
final class ProfileImageHtmlRenderer
{
    /**
     * Construct around the collaborator that resolves a photo's URL.
     *
     * @param CloudflareImagesUrlBuilder|null $images_url_builder The URL builder, or null if not configured.
     */
    public function __construct(private readonly ?CloudflareImagesUrlBuilder $images_url_builder)
    {
    }

    /**
     * Render one `<img>` tag for an actor/studio photo, or `''` if there
     * is no photo to render (`$image_id` is null) or Cloudflare Images
     * isn't configured — the same graceful-degradation shape every other
     * optional-image path in this project already uses, never a fatal.
     *
     * @param int|null                   $image_id An `Actor`/`Studio` photo/logo image ID, or null for no photo.
     * @param ImageSize                  $size     Which size preset to render.
     * @param array<string, bool|string> $args     `alt`/`class` (string). All optional.
     */
    public function render(?int $image_id, ImageSize $size, array $args = []): string
    {
        if (null === $image_id || null === $this->images_url_builder) {
            return '';
        }

        $alt   = self::string_arg($args, 'alt', '');
        $class = trim('tube-player__profile-photo ' . self::string_arg($args, 'class', ''));

        return sprintf(
            '<img src="%1$s" width="%2$d" height="%3$d" alt="%4$s" loading="lazy" decoding="async"'
                . ' class="%5$s" />',
            esc_url($this->images_url_builder->url($image_id, $size->value)),
            $size->width(),
            $size->height(),
            esc_attr($alt),
            esc_attr($class)
        );
    }

    /**
     * Read a string option from $args, genuinely checked rather than
     * blindly cast — see `ImageHtmlRenderer::string_arg()` for why.
     *
     * @param array<string, bool|string> $args     The renderer's $args.
     * @param string                     $key      Which option to read.
     * @param string                     $fallback The value to use if $key is missing or not a string.
     */
    private static function string_arg(array $args, string $key, string $fallback): string
    {
        return isset($args[ $key ]) && is_string($args[ $key ]) ? $args[ $key ] : $fallback;
    }
}
