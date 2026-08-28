<?php
/**
 * Integration tests for TextNormalizer::normalize().
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Tests\Integration\Search;

use PHPUnit\Framework\TestCase;
use Tube_Search\Search\TextNormalizer;

/**
 * Exercises `TextNormalizer::normalize()` — the one pipeline shared by
 * indexing and searching — against real WordPress (`remove_accents()`).
 * Regression coverage for the real bug found in manual browser testing:
 * "tran ha linh" matched "Clip Trần Hà Linh" but "trần hà linh" did not
 * — asymmetric normalization. Every pair below normalizes to the exact
 * same string, which is what makes the two sides of a search comparison
 * equal regardless of which form either the indexed text or the query
 * happened to use.
 */
final class TextNormalizerIntegrationTest extends TestCase
{
    /**
     * The exact indexed title from the real bug report normalizes to plain, lowercased ASCII.
     */
    public function test_normalizes_the_real_indexed_title(): void
    {
        self::assertSame('clip tran ha linh', TextNormalizer::normalize('Clip Trần Hà Linh'));
    }

    /**
     * Every full-phrase query the required test matrix lists normalizes to the same value, regardless of
     * accents or case.
     *
     * @dataProvider provide_equivalent_full_phrase_queries
     *
     * @param string $query The query text to normalize.
     */
    public function test_equivalent_full_phrase_queries_normalize_identically(string $query): void
    {
        self::assertSame('tran ha linh', TextNormalizer::normalize($query));
    }

    /**
     * Data provider for self::test_equivalent_full_phrase_queries_normalize_identically().
     *
     * @return list<array{0: string}>
     */
    public static function provide_equivalent_full_phrase_queries(): array
    {
        return [
            ['trần hà linh'],
            ['Trần Hà Linh'],
            ['tran ha linh'],
            ['Tran Ha Linh'],
        ];
    }

    /**
     * A partial query ("hà linh"/"ha linh"/"clip trần"/"clip tran") normalizes to a substring of the full
     * normalized title, which is what makes it findable via FULLTEXT natural language matching.
     *
     * @dataProvider provide_partial_queries
     *
     * @param string $query The query text to normalize.
     */
    public function test_partial_queries_normalize_to_a_substring_of_the_title(string $query): void
    {
        $normalized_title = TextNormalizer::normalize('Clip Trần Hà Linh');
        $normalized_query = TextNormalizer::normalize($query);

        self::assertStringContainsString($normalized_query, $normalized_title);
    }

    /**
     * Data provider for self::test_partial_queries_normalize_to_a_substring_of_the_title().
     *
     * @return list<array{0: string}>
     */
    public static function provide_partial_queries(): array
    {
        return [
            ['hà linh'],
            ['ha linh'],
            ['clip trần'],
            ['clip tran'],
        ];
    }

    /**
     * Vietnamese "Đ"/"đ" — an atomic letter with no Unicode decomposition into "D"/"d" plus a combining
     * mark, so collation-level accent folding alone (proven live: it does not fold this one) cannot handle
     * it. This is exactly why WordPress core's own remove_accents() is used rather than relying on the
     * database's collation.
     */
    public function test_normalizes_vietnamese_d_with_stroke(): void
    {
        self::assertSame('dang', TextNormalizer::normalize('đặng'));
        self::assertSame('dang', TextNormalizer::normalize('Đặng'));
        self::assertSame('dang', TextNormalizer::normalize('dang'));
    }

    /**
     * A mixed accented/plain phrase.
     */
    public function test_normalizes_a_mixed_accented_phrase(): void
    {
        self::assertSame('gai dep', TextNormalizer::normalize('gái đẹp'));
        self::assertSame('gai dep', TextNormalizer::normalize('gai dep'));
    }

    /**
     * A single accented word.
     */
    public function test_normalizes_a_single_accented_word(): void
    {
        self::assertSame('gai', TextNormalizer::normalize('gái'));
        self::assertSame('gai', TextNormalizer::normalize('gai'));
    }

    /**
     * Mixed case folds to lowercase.
     */
    public function test_lowercases(): void
    {
        self::assertSame(
            TextNormalizer::normalize('trần hà linh'),
            TextNormalizer::normalize('TRẦN HÀ LINH')
        );
    }

    /**
     * Runs of internal whitespace collapse to one space, and leading/trailing whitespace is trimmed.
     */
    public function test_normalizes_whitespace(): void
    {
        self::assertSame('tran ha linh', TextNormalizer::normalize('  tran   ha linh  '));
        self::assertSame(
            TextNormalizer::normalize('tran ha linh'),
            TextNormalizer::normalize("tran\tha\nlinh")
        );
    }

    /**
     * An already-normalized string round-trips unchanged (idempotent).
     */
    public function test_is_idempotent(): void
    {
        $once  = TextNormalizer::normalize('Trần Hà Linh');
        $twice = TextNormalizer::normalize($once);

        self::assertSame($once, $twice);
    }

    /**
     * Precomposed (NFC) and combining-mark-decomposed (NFD) Unicode forms of the same visible text
     * normalize identically — proves step 1 (Unicode normalization) actually does its job, not just that
     * remove_accents() happens to work on whichever form PHP source files use.
     */
    public function test_nfc_and_nfd_forms_normalize_identically(): void
    {
        $nfc = "tr\u{1EA7}n"; // U+1EA7: precomposed "ầ".
        $nfd = "tra\u{0302}\u{0300}n"; // "a" + combining circumflex (U+0302) + combining grave (U+0300).

        self::assertNotSame($nfc, $nfd, 'Sanity check: these really are different byte sequences.');
        self::assertSame(TextNormalizer::normalize($nfc), TextNormalizer::normalize($nfd));
        self::assertSame('tran', TextNormalizer::normalize($nfc));
    }
}
