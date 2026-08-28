<?php
/**
 * Unit tests for ContentSanitizer.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Tests\Unit\Comments;

use PHPUnit\Framework\TestCase;
use Tube_Comments\Comments\ContentSanitizer;

/**
 * Exercises ContentSanitizer's XSS/oversized-payload/whitespace-abuse
 * defenses (Phase 20) — the single choke point every comment's content
 * passes through before it is ever stored, since `wp_tube_comments.content`
 * always stores plain text, never HTML.
 */
final class ContentSanitizerTest extends TestCase
{
    /**
     * The sanitizer under test.
     *
     * @var ContentSanitizer
     */
    private ContentSanitizer $sanitizer;

    /**
     * Build a fresh sanitizer for each test.
     */
    protected function setUp(): void
    {
        $this->sanitizer = new ContentSanitizer();
    }

    /**
     * Plain text with no markup survives unchanged.
     */
    public function test_plain_text_survives_unchanged(): void
    {
        self::assertSame('Video hay quá!', $this->sanitizer->sanitize('Video hay quá!'));
    }

    /**
     * A <script> tag and its inner content are removed entirely, not just the tag.
     */
    public function test_strips_a_script_tag_injection(): void
    {
        $result = $this->sanitizer->sanitize('<script>alert(1)</script>hello');

        self::assertSame('hello', $result);
        self::assertStringNotContainsString('<script', $result);
    }

    /**
     * An <img onerror=...> injection is stripped down to its surrounding plain text.
     */
    public function test_strips_an_image_onerror_injection(): void
    {
        $result = $this->sanitizer->sanitize('<img src=x onerror=alert(2)> hello');

        self::assertStringNotContainsString('<img', $result);
        self::assertStringNotContainsString('onerror', $result);
    }

    /**
     * A combined script+image injection reduces to only its trailing plain text.
     */
    public function test_strips_combined_script_and_image_injection_leaving_only_trailing_text(): void
    {
        // The exact live-browser XSS payload verified earlier this
        // session against the real REST endpoint.
        $result = $this->sanitizer->sanitize('<script>alert(1)</script><img src=x onerror=alert(2)> hello');

        self::assertSame('hello', $result);
    }

    /**
     * Emoji are not markup and survive sanitizing.
     */
    public function test_preserves_emoji(): void
    {
        self::assertSame('Hay quá 👍🔥', $this->sanitizer->sanitize('Hay quá 👍🔥'));
    }

    /**
     * A single line break between two lines is preserved.
     */
    public function test_preserves_single_line_breaks(): void
    {
        self::assertSame("Dòng 1\nDòng 2", $this->sanitizer->sanitize("Dòng 1\nDòng 2"));
    }

    /**
     * Windows-style CRLF line endings are normalized to a single \n.
     */
    public function test_normalizes_windows_line_endings(): void
    {
        self::assertSame("Dòng 1\nDòng 2", $this->sanitizer->sanitize("Dòng 1\r\nDòng 2"));
    }

    /**
     * Three or more consecutive blank lines collapse to exactly one, preventing a visual flood.
     */
    public function test_collapses_three_or_more_blank_lines_to_one(): void
    {
        $result = $this->sanitizer->sanitize("A\n\n\n\n\nB");

        self::assertSame("A\n\nB", $result);
    }

    /**
     * Leading and trailing whitespace is trimmed.
     */
    public function test_trims_leading_and_trailing_whitespace(): void
    {
        self::assertSame('hello', $this->sanitizer->sanitize("   hello  \n"));
    }

    /**
     * Content that is entirely markup/whitespace sanitizes to an empty string.
     */
    public function test_all_markup_and_whitespace_becomes_an_empty_string(): void
    {
        self::assertSame('', $this->sanitizer->sanitize('<div>   </div>'));
    }

    /**
     * A payload longer than MAX_LENGTH is truncated to exactly MAX_LENGTH characters.
     */
    public function test_truncates_oversized_payloads_to_the_max_length(): void
    {
        $huge = str_repeat('a', ContentSanitizer::MAX_LENGTH + 500);

        $result = $this->sanitizer->sanitize($huge);

        self::assertSame(ContentSanitizer::MAX_LENGTH, mb_strlen($result));
    }

    /**
     * Truncation counts multibyte characters, not raw bytes.
     */
    public function test_truncation_is_multibyte_aware(): void
    {
        $huge = str_repeat('👍', ContentSanitizer::MAX_LENGTH + 50);

        $result = $this->sanitizer->sanitize($huge);

        self::assertSame(ContentSanitizer::MAX_LENGTH, mb_strlen($result));
    }
}
