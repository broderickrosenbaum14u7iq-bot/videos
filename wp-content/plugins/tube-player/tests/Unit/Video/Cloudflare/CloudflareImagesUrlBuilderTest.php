<?php
/**
 * Unit tests for CloudflareImagesUrlBuilder.
 *
 * @package Tube_Player
 */

declare(strict_types=1);

namespace Tube_Player\Tests\Unit\Video\Cloudflare;

use PHPUnit\Framework\TestCase;
use Tube_Player\Video\Cloudflare\CloudflareImagesUrlBuilder;

/**
 * Pure string construction — no fakes needed.
 */
final class CloudflareImagesUrlBuilderTest extends TestCase
{
    /**
     * The URL follows Cloudflare Images' documented delivery shape.
     */
    public function test_url_follows_the_cloudflare_images_delivery_shape(): void
    {
        $builder = new CloudflareImagesUrlBuilder('abc123hash');

        self::assertSame(
            'https://imagedelivery.net/abc123hash/42/grid_card',
            $builder->url(42, 'grid_card')
        );
    }
}
