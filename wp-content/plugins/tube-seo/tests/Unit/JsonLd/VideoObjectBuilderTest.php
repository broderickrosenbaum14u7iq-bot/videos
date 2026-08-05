<?php
/**
 * Unit tests for VideoObjectBuilder.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Tests\Unit\JsonLd;

use PHPUnit\Framework\TestCase;
use Tube_Seo\JsonLd\VideoObjectBuilder;

/**
 * Exercises VideoObjectBuilder's pure array-building logic — no WordPress.
 */
final class VideoObjectBuilderTest extends TestCase
{
    /**
     * Build() produces the documented schema.org VideoObject shape.
     */
    public function test_build_produces_the_documented_shape(): void
    {
        $result = VideoObjectBuilder::build(
            'My Video',
            'A description.',
            'https://example.com/thumb.jpg',
            '2026-01-01T00:00:00+00:00',
            'PT5M30S',
            'https://example.com/embed/abc',
            42
        );

        self::assertSame('https://schema.org', $result['@context']);
        self::assertSame('VideoObject', $result['@type']);
        self::assertSame('My Video', $result['name']);
        self::assertSame('A description.', $result['description']);
        self::assertSame(['https://example.com/thumb.jpg'], $result['thumbnailUrl']);
        self::assertSame('2026-01-01T00:00:00+00:00', $result['uploadDate']);
        self::assertSame('https://example.com/embed/abc', $result['embedUrl']);
        self::assertArrayHasKey('duration', $result);
        self::assertSame('PT5M30S', $result['duration'] ?? null);
        self::assertSame(42, $result['interactionStatistic']['userInteractionCount']);
    }

    /**
     * A null duration omits the duration field entirely, rather than including a null value.
     */
    public function test_null_duration_omits_the_duration_field(): void
    {
        $result = VideoObjectBuilder::build('Title', 'Desc', 'https://x/y.jpg', '2026-01-01', null, 'https://x/e', 0);

        self::assertArrayNotHasKey('duration', $result);
    }

    /**
     * Iso8601_duration() formats a duration in seconds correctly.
     *
     * @dataProvider provide_durations
     *
     * @param int    $seconds  The duration, in seconds.
     * @param string $expected The expected ISO 8601 duration string.
     */
    public function test_iso8601_duration_formats_correctly(int $seconds, string $expected): void
    {
        self::assertSame($expected, VideoObjectBuilder::iso8601_duration($seconds));
    }

    /**
     * Data provider for test_iso8601_duration_formats_correctly().
     *
     * @return list<array{0: int, 1: string}>
     */
    public static function provide_durations(): array
    {
        return [
            [0, 'PT0S'],
            [5, 'PT5S'],
            [60, 'PT1M'],
            [90, 'PT1M30S'],
            [3600, 'PT1H'],
            [3661, 'PT1H1M1S'],
            [7325, 'PT2H2M5S'],
        ];
    }

    /**
     * A negative duration is clamped to zero, not a negative ISO 8601 string.
     */
    public function test_negative_duration_is_clamped_to_zero(): void
    {
        self::assertSame('PT0S', VideoObjectBuilder::iso8601_duration(-5));
    }
}
