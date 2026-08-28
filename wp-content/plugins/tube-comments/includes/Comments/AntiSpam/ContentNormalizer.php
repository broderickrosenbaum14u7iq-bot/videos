<?php
/**
 * Pure, WordPress-free text normalization for anti-spam comparisons.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Comments\AntiSpam;

/**
 * Pure, WordPress-free text normalization for anti-spam comparisons —
 * kept dependency-free (no `$wpdb`, no WordPress functions) so it can be
 * exercised by the plain PHPUnit Unit suite exactly like
 * `Tube_Comments\Comments\ContentSanitizer`, unlike `SpamGuard` itself
 * (which reads `$wpdb`/`current_user_can()`/`get_userdata()` and is
 * therefore covered by the Integration suite instead).
 *
 * Every method here operates on already-`ContentSanitizer`-sanitized
 * text (plain text, NFC-normalized, tag-free) — normalization here is
 * about comparison, not safety.
 */
final class ContentNormalizer
{
    /**
     * Normalize for an EXACT-duplicate comparison (Phase "duplicate
     * content"): trims, collapses internal whitespace runs to one
     * space, and case-folds via `mb_strtolower()` so Vietnamese
     * diacritics compare correctly (e.g. "Hay Quá" and "hay quá" are
     * the same comment). Punctuation is deliberately preserved here —
     * "hay quá" and "hay quá!" are NOT the same string under this
     * normalization; that near-miss is instead caught by
     * {@see self::for_flood_comparison()}'s separate, looser rule.
     *
     * @param string $content Already-sanitized comment content.
     */
    public static function for_exact_duplicate(string $content): string
    {
        $text = trim($content);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_strtolower($text, 'UTF-8');
    }

    /**
     * Normalize for a NEAR-duplicate/flood comparison: case-folds,
     * strips every character that is not a Unicode letter, digit, or
     * whitespace (removing punctuation/symbols/emoji entirely — so
     * "hay quá", "hay quá!", and "hay quá!!" all normalize to the same
     * string), then collapses whitespace. Deliberately looser than
     * {@see self::for_exact_duplicate()}; `SpamGuard` only applies this
     * comparison to the single most recent comment within a short
     * window (see `SpamPolicy::FLOOD_WINDOW_SECONDS`), so it never
     * incorrectly flags two unrelated comments that happen to share
     * only punctuation.
     *
     * @param string $content Already-sanitized comment content.
     */
    public static function for_flood_comparison(string $content): string
    {
        $text = mb_strtolower($content, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * How many Unicode letters/digits $content contains — punctuation,
     * whitespace, and emoji/symbols never count. Used by
     * {@see self::has_minimum_quality()}.
     *
     * @param string $content Already-sanitized comment content.
     */
    public static function meaningful_char_count(string $content): int
    {
        $meaningful = preg_replace('/[^\p{L}\p{N}]+/u', '', $content) ?? '';

        return mb_strlen($meaningful, 'UTF-8');
    }

    /**
     * Whether $content clears the minimum-quality bar: at least
     * `SpamPolicy::MIN_MEANINGFUL_CHARS` Unicode letters/digits.
     *
     * The exact rule chosen (documented here since the anti-spam spec
     * requires it): a comment must contain at least 2 Unicode
     * letters/digits (`\p{L}`/`\p{N}`) after every other character is
     * stripped. This rejects whitespace-only, punctuation-only
     * (".", "..", "...") and single-emoji-only content (emoji are
     * Unicode symbols, not `\p{L}`/`\p{N}`, so they never count as
     * "meaningful" under this rule) — while accepting any real word,
     * including a single short Vietnamese word or a lone two-digit
     * number, without demanding anything close to a full sentence.
     *
     * @param string $content Already-sanitized comment content.
     */
    public static function has_minimum_quality(string $content): bool
    {
        return self::meaningful_char_count($content) >= SpamPolicy::MIN_MEANINGFUL_CHARS;
    }

    /**
     * How many `http://`/`https://` links $content contains. Timestamp
     * patterns such as "02:35" never match — they carry no
     * `http(s)://` prefix — so this cannot misclassify a timestamp
     * reference as a link.
     *
     * @param string $content Already-sanitized comment content.
     */
    public static function external_link_count(string $content): int
    {
        $count = preg_match_all('#https?://#i', $content);

        return false === $count ? 0 : $count;
    }
}
