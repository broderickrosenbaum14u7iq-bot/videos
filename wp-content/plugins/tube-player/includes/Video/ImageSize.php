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
 */
enum ImageSize: string
{
    case GridCard = 'grid_card';
    case Hero     = 'hero';
    case OgImage  = 'og_image';

    /**
     * The pixel width to request for this size.
     */
    public function width(): int
    {
        return match ($this) {
            self::GridCard => 320,
            self::Hero => 1280,
            self::OgImage => 1200,
        };
    }

    /**
     * The pixel height to request for this size. `GridCard`/`Hero` are
     * 16:9 (this project's default player aspect ratio); `OgImage` is
     * 1.91:1, the size social platforms actually render an OG image at.
     */
    public function height(): int
    {
        return match ($this) {
            self::GridCard => 180,
            self::Hero => 720,
            self::OgImage => 630,
        };
    }
}
