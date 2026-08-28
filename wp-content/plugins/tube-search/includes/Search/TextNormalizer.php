<?php
/**
 * The one text-normalization pipeline shared by indexing and searching.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Search;

use Normalizer;

/**
 * The one text-normalization pipeline shared by both indexing
 * (`Tube_Search\Index\SearchIndexRepository::upsert()`, writing
 * `wp_tube_search_index.search_text_normalized`) and searching
 * (`Tube_Search\Index\SearchIndexRepository::search()`, normalizing the
 * incoming query before matching against that same column) — the same
 * transformation applied to both sides is what makes "trần" (indexed)
 * and "tran"/"trần" (queried) compare equal, instead of relying on
 * MySQL collation folding, which is inconsistent for Vietnamese: the
 * `utf8mb4_unicode_520_ci` collation this project's `wp_tube_search_index`
 * uses folds precomposed accented Latin vowels (a UCA secondary-weight
 * difference — verified live: "tran" already matched "Trần" through the
 * old `MATCH(title, description)` FULLTEXT column via collation alone),
 * but Vietnamese "Đ"/"đ" has no Unicode decomposition into "D"/"d" plus a
 * combining mark — it is an atomic letter — so no collation-level accent
 * folding reduces it, and a query for "dang" never matched an indexed
 * "Đặng" (also verified live). Normalizing explicitly in PHP, once, and
 * comparing already-folded text removes the dependency on collation
 * behavior for either case.
 *
 * Steps, applied in order — see `self::normalize()`'s inline comments for why each is here and in this order:
 * 1. Unicode normalization (NFC) — makes the transliteration table below reliable regardless of whether the
 *    input arrived as precomposed or combining-mark-decomposed Unicode.
 * 2. Vietnamese accent folding/transliteration — WordPress core's own `remove_accents()`, not a hand-built
 *    table: it already has explicit, maintained coverage for Vietnamese (including "Đ"/"đ", which has no
 *    Unicode decomposition to fall back on) alongside every other Latin-script diacritic WordPress supports
 *    for slug generation. Reusing it is deliberate — no second, ad-hoc, hardcoded transliteration table to
 *    maintain or drift out of sync with WordPress's own.
 * 3. Lowercasing.
 * 4. Whitespace normalization (collapse runs of whitespace, trim).
 *
 * WordPress-coupled (`remove_accents()`) — verified via integration
 * tests and live checks, not unit-tested, the same split this project
 * applies to every thin real-output adapter that depends on a WordPress
 * core function.
 */
final class TextNormalizer
{
    /**
     * Normalize one piece of text for comparison — used identically for
     * an indexed title/description and for a search query, so the two
     * sides of a comparison are always produced by the exact same steps.
     *
     * @param string $text The text to normalize (already real, decoded UTF-8 — not a URL-encoded value; URL
     *     decoding is a distinct, prior concern, not part of this pipeline).
     */
    public static function normalize(string $text): string
    {
        // Step 1: Unicode normalization. A precomposed accented letter
        // ("ầ", one codepoint) and its canonically-equivalent decomposed
        // form ("a" + combining circumflex + combining grave, three
        // codepoints) are the same character to a human but different
        // byte sequences — remove_accents()'s transliteration table below
        // is keyed on the precomposed forms, so normalizing to NFC first
        // makes it reliable regardless of which form the input arrived
        // in. Guarded by class_exists(): the `intl` extension (confirmed
        // present in this project's own environment) provides
        // `Normalizer`, but this step degrades gracefully rather than
        // fataling if it's ever unavailable — the common case (input
        // already precomposed, which is what real browsers/OSes typically
        // produce for typed text) still works correctly without it.
        if (class_exists(Normalizer::class)) {
            $form_c = Normalizer::normalize($text, Normalizer::FORM_C);

            if (false !== $form_c) {
                $text = $form_c;
            }
        }

        // Step 2: Vietnamese accent folding/transliteration — see this class's own docblock for why
        // remove_accents() specifically, not a hand-built table.
        $text = remove_accents($text);

        // Step 3: lowercase.
        $text = strtolower($text);

        // Step 4: whitespace normalization — collapse any run of
        // whitespace (multiple spaces, tabs, newlines) to one space, then
        // trim the ends.
        $collapsed = preg_replace('/\s+/u', ' ', $text);

        if (null !== $collapsed) {
            $text = $collapsed;
        }

        return trim($text);
    }
}
