<?php
/**
 * Unit tests for WebSiteBuilder.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Tests\Unit\JsonLd;

use PHPUnit\Framework\TestCase;
use Tube_Seo\JsonLd\WebSiteBuilder;

/**
 * Exercises WebSiteBuilder's pure array-building logic — no WordPress.
 */
final class WebSiteBuilderTest extends TestCase
{
    /**
     * Build() produces the documented schema.org WebSite shape.
     */
    public function test_build_produces_the_documented_shape(): void
    {
        $result = WebSiteBuilder::build('Example Site', 'https://example.com/');

        self::assertSame('https://schema.org', $result['@context']);
        self::assertSame('WebSite', $result['@type']);
        self::assertSame('Example Site', $result['name']);
        self::assertSame('https://example.com/', $result['url']);
        self::assertArrayNotHasKey('potentialAction', $result);
        self::assertArrayNotHasKey('publisher', $result);
    }
}
