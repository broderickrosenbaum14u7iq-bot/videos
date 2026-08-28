<?php
/**
 * Reduces raw comment input to safe, storable plain text.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Comments;

/**
 * Reduces raw comment input to safe, storable plain text, per Phase 20.
 *
 * `wp_tube_comments.content` always stores plain text — never HTML
 * (`Migration001CreateCommentsTable`'s own docblock) — so this strips
 * every tag outright rather than trying to allow a safe HTML subset.
 * Emoji and line breaks survive because they were never tags to begin
 * with; timestamp/URL linking happens later, at render time
 * ({@see \Tube_Comments\Render\ContentRenderer}), from this same plain
 * text, which is what lets that rendering behavior change later without
 * a data migration.
 */
final class ContentSanitizer
{
    /**
     * Maximum stored length, in characters — bounds oversized payloads
     * (Phase 20) well above any real comment while staying far below
     * anything that could strain rendering or storage.
     */
    public const MAX_LENGTH = 2000;

    /**
     * Reduce raw comment input to safe, storable plain text.
     *
     * @param string $raw The visitor's raw, untrusted comment text.
     *
     * @return string Sanitized, storable plain text — may be empty if $raw was all markup/whitespace.
     */
    public function sanitize(string $raw): string
    {
        $text = wp_strip_all_tags($raw);

        // Normalize Windows/old-Mac line endings to a single \n, and
        // collapse 3+ consecutive blank lines down to at most one, so a
        // pasted wall of blank lines can't be used to visually flood the
        // thread (Phase 20's "Unicode/payload abuse where practical").
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        if (function_exists('normalizer_normalize')) {
            $normalized = normalizer_normalize($text, \Normalizer::FORM_C);

            if (false !== $normalized) {
                $text = $normalized;
            }
        }

        $text = trim($text);

        if (mb_strlen($text) > self::MAX_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_LENGTH);
        }

        return $text;
    }
}
