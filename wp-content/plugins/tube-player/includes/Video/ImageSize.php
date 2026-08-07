<?php
/**
 * Named poster/thumbnail size presets.
 *
 * @package Tube_Player
 */

declare(strict_types=1);

namespace Tube_Player\Video;

/**
 * Named poster/thumbnail size presets, per ARCHITECTURE.md §8 ("the
 * appropriate variant (grid-card, hero, OG-image)"). Each case's value
 * doubles as the Cloudflare Images variant name for the poster-override
 * path (`CloudflareImagesUrlBuilder`), so a variant configured as
 * `grid_card` in the Cloudflare dashboard lines up with
 * `ImageSize::GridCard` with no separate mapping table to maintain.
 *
 * `Avatar` (Phase 13) is the one case with no Stream-thumbnail fallback
 * path — it's only ever used via `ProfileImageHtmlRenderer` for an
 * actor/studio photo (a Cloudflare Images ID, never a video's Stream
 * UID). Requires a matching `avatar` variant to exist in the Cloudflare
 * Images dashboard, the same operational dependency every other case
 * already has for its own variant name.
 */
enum ImageSize: string
{
    case GridCard = 'grid_card';
    case Hero     = 'hero';
    case OgImage  = 'og_image';
    case Avatar   = 'avatar';

    /**
     * The pixel width to request for this size.
     */
    public function width(): int
    {
        return match ($this) {
            self::GridCard => 320,
            self::Hero => 1280,
            self::OgImage => 1200,
            self::Avatar => 400,
        };
    }

    /**
     * The pixel height to request for this size. `GridCard`/`Hero` are
     * 16:9 (this project's default player aspect ratio); `OgImage` is
     * 1.91:1, the size social platforms actually render an OG image at;
     * `Avatar` is square (a headshot/logo, not a video frame).
     */
    public function height(): int
    {
        return match ($this) {
            self::GridCard => 180,
            self::Hero => 720,
            self::OgImage => 630,
            self::Avatar => 400,
        };
    }
}
