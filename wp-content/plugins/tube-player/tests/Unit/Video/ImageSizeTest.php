<?php
/**
 * Unit tests for ImageSize.
 *
 * @package Tube_Player
 */

declare(strict_types=1);

namespace Tube_Player\Tests\Unit\Video;

use PHPUnit\Framework\TestCase;
use Tube_Player\Video\ImageSize;

/**
 * Confirms every size preset resolves to a real, positive dimension
 * pair, and that string-to-enum resolution (as used by
 * `tube_player_get_image_html()`'s `$size` parameter) round-trips.
 */
final class ImageSizeTest extends TestCase
{
    /**
     * Every case has a positive width and height.
     */
    public function test_every_size_has_positive_dimensions(): void
    {
        foreach (ImageSize::cases() as $size) {
            self::assertGreaterThan(0, $size->width());
            self::assertGreaterThan(0, $size->height());
        }
    }

    /**
     * `tryFrom()` resolves every documented template-tag `$size` string.
     *
     * The reverse case — an unrecognized size string resolving to null —
     * isn't a separate test here: PHPStan already proves that
     * statically for any literal string outside the enum, so there is
     * nothing left for a unit test to add. `tube_player_get_image_html()`
     * relying on that null (returning '' for a bad `$size` argument
     * instead of guessing) is exercised live, via tests/Integration.
     */
    public function test_tryfrom_resolves_every_documented_size_string(): void
    {
        self::assertSame(ImageSize::GridCard, ImageSize::tryFrom('grid_card'));
        self::assertSame(ImageSize::Hero, ImageSize::tryFrom('hero'));
        self::assertSame(ImageSize::OgImage, ImageSize::tryFrom('og_image'));
        self::assertSame(ImageSize::Avatar, ImageSize::tryFrom('avatar'));
    }

    /**
     * `Avatar` (Phase 13) is square — an actor/studio photo is a
     * headshot/logo crop, not a 16:9 video frame.
     */
    public function test_avatar_is_square(): void
    {
        self::assertSame(ImageSize::Avatar->width(), ImageSize::Avatar->height());
    }
}
