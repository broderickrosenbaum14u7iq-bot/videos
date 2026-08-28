<?php
/**
 * Unit tests for ContentNormalizer.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Tests\Unit\Comments\AntiSpam;

use PHPUnit\Framework\TestCase;
use Tube_Comments\Comments\AntiSpam\ContentNormalizer;

/**
 * Exercises ContentNormalizer's pure text comparisons — the logic
 * `SpamGuard` relies on for duplicate-content, near-duplicate/flood, and
 * minimum-quality detection, kept dependency-free so it can be tested
 * without a database (unlike `SpamGuard` itself, which is covered by the
 * Integration suite).
 */
final class ContentNormalizerTest extends TestCase
{
    /**
     * Whitespace-collapsing and case-folding treat these two comments as an exact duplicate.
     */
    public function test_exact_duplicate_normalization_collapses_whitespace_and_case(): void
    {
        self::assertSame(
            ContentNormalizer::for_exact_duplicate('  Hay   Quá  '),
            ContentNormalizer::for_exact_duplicate('hay quá')
        );
    }

    /**
     * Exact-duplicate normalization deliberately preserves punctuation --
     * "hay quá" and "hay quá!" are NOT the same string under this rule
     * (that near-miss is instead the flood comparison's job).
     */
    public function test_exact_duplicate_normalization_preserves_punctuation(): void
    {
        self::assertNotSame(
            ContentNormalizer::for_exact_duplicate('hay quá'),
            ContentNormalizer::for_exact_duplicate('hay quá!')
        );
    }

    /**
     * Flood normalization strips punctuation entirely, so "hay quá",
     * "hay quá!", and "hay quá!!" all normalize identically.
     */
    public function test_flood_normalization_treats_punctuation_variants_as_identical(): void
    {
        $base = ContentNormalizer::for_flood_comparison('hay quá');

        self::assertSame($base, ContentNormalizer::for_flood_comparison('hay quá!'));
        self::assertSame($base, ContentNormalizer::for_flood_comparison('hay quá!!'));
        self::assertSame($base, ContentNormalizer::for_flood_comparison('Hay Quá!!!'));
    }

    /**
     * Flood normalization does not conflate two genuinely different comments.
     */
    public function test_flood_normalization_does_not_conflate_different_content(): void
    {
        self::assertNotSame(
            ContentNormalizer::for_flood_comparison('hay quá'),
            ContentNormalizer::for_flood_comparison('dở quá')
        );
    }

    /**
     * A punctuation-only comment has zero meaningful characters.
     */
    public function test_meaningful_char_count_is_zero_for_punctuation_only(): void
    {
        self::assertSame(0, ContentNormalizer::meaningful_char_count('...'));
        self::assertSame(0, ContentNormalizer::meaningful_char_count('.'));
        self::assertSame(0, ContentNormalizer::meaningful_char_count('!!!'));
    }

    /**
     * A whitespace-only comment has zero meaningful characters.
     */
    public function test_meaningful_char_count_is_zero_for_whitespace_only(): void
    {
        self::assertSame(0, ContentNormalizer::meaningful_char_count("   \n\t  "));
    }

    /**
     * A single emoji has zero meaningful characters -- emoji are Unicode symbols, not letters/digits.
     */
    public function test_meaningful_char_count_is_zero_for_a_single_emoji(): void
    {
        self::assertSame(0, ContentNormalizer::meaningful_char_count('👍'));
    }

    /**
     * Vietnamese letters with diacritics count as meaningful characters.
     */
    public function test_meaningful_char_count_counts_vietnamese_letters(): void
    {
        self::assertSame(6, ContentNormalizer::meaningful_char_count('hay quá'));
    }

    /**
     * Digits count as meaningful characters too.
     */
    public function test_meaningful_char_count_counts_digits(): void
    {
        self::assertSame(2, ContentNormalizer::meaningful_char_count('42'));
    }

    /**
     * `has_minimum_quality()` rejects "." and "..." (below the 2-character threshold).
     */
    public function test_has_minimum_quality_rejects_dots(): void
    {
        self::assertFalse(ContentNormalizer::has_minimum_quality('.'));
        self::assertFalse(ContentNormalizer::has_minimum_quality('..'));
        self::assertFalse(ContentNormalizer::has_minimum_quality('...'));
    }

    /**
     * `has_minimum_quality()` accepts a short real word.
     */
    public function test_has_minimum_quality_accepts_a_short_real_word(): void
    {
        self::assertTrue(ContentNormalizer::has_minimum_quality('ok'));
        self::assertTrue(ContentNormalizer::has_minimum_quality('hay quá'));
    }

    /**
     * `external_link_count()` counts http/https occurrences.
     */
    public function test_external_link_count_counts_links(): void
    {
        self::assertSame(0, ContentNormalizer::external_link_count('không có link nào'));
        self::assertSame(1, ContentNormalizer::external_link_count('xem tại http://example.com nhé'));
        self::assertSame(
            2,
            ContentNormalizer::external_link_count('http://a.com và https://b.com')
        );
    }

    /**
     * `external_link_count()` never counts a timestamp reference like "02:35" as a link.
     */
    public function test_external_link_count_never_matches_a_timestamp(): void
    {
        self::assertSame(0, ContentNormalizer::external_link_count('xem đoạn 02:35 hay nhất, với cả 1:02:30 nữa'));
    }
}
