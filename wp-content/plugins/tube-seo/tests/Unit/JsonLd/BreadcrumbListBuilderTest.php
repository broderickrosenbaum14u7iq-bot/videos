<?php
/**
 * Unit tests for BreadcrumbListBuilder.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Tests\Unit\JsonLd;

use PHPUnit\Framework\TestCase;
use Tube_Seo\JsonLd\BreadcrumbListBuilder;

/**
 * Exercises BreadcrumbListBuilder's pure array-building logic — no WordPress.
 */
final class BreadcrumbListBuilderTest extends TestCase
{
    /**
     * Build() produces the documented schema.org BreadcrumbList shape, with 1-indexed positions.
     */
    public function test_build_produces_the_documented_shape(): void
    {
        $result = BreadcrumbListBuilder::build(
            [
                [
                    'name' => 'Home',
                    'url'  => 'https://example.com/',
                ],
                [
                    'name' => 'Category',
                    'url'  => 'https://example.com/category/x/',
                ],
                [
                    'name' => 'Video',
                    'url'  => 'https://example.com/watch/x/',
                ],
            ]
        );

        self::assertSame('https://schema.org', $result['@context']);
        self::assertSame('BreadcrumbList', $result['@type']);
        self::assertCount(3, $result['itemListElement']);

        self::assertSame(1, $result['itemListElement'][0]['position']);
        self::assertSame('Home', $result['itemListElement'][0]['name']);
        self::assertSame('https://example.com/', $result['itemListElement'][0]['item']);

        self::assertSame(3, $result['itemListElement'][2]['position']);
        self::assertSame('Video', $result['itemListElement'][2]['name']);
    }

    /**
     * An empty item list produces an empty itemListElement, not an error.
     */
    public function test_empty_items_produces_empty_list(): void
    {
        $result = BreadcrumbListBuilder::build([]);

        self::assertSame([], $result['itemListElement']);
    }
}
