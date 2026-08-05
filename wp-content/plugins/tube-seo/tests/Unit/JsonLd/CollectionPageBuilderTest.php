<?php
/**
 * Unit tests for CollectionPageBuilder.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Tests\Unit\JsonLd;

use PHPUnit\Framework\TestCase;
use Tube_Seo\JsonLd\CollectionPageBuilder;

/**
 * Exercises CollectionPageBuilder's pure array-building logic — no WordPress.
 */
final class CollectionPageBuilderTest extends TestCase
{
    /**
     * Build() produces the documented schema.org CollectionPage shape.
     */
    public function test_build_produces_the_documented_shape(): void
    {
        $result = CollectionPageBuilder::build('Action Movies', 'https://example.com/category/action/', 42);

        self::assertSame('https://schema.org', $result['@context']);
        self::assertSame('CollectionPage', $result['@type']);
        self::assertSame('Action Movies', $result['name']);
        self::assertSame('https://example.com/category/action/', $result['url']);
        self::assertSame('ItemList', $result['mainEntity']['@type']);
        self::assertSame(42, $result['mainEntity']['numberOfItems']);
    }

    /**
     * A zero item count is represented faithfully, not omitted.
     */
    public function test_zero_item_count(): void
    {
        $result = CollectionPageBuilder::build('Empty', 'https://example.com/empty/', 0);

        self::assertSame(0, $result['mainEntity']['numberOfItems']);
    }
}
