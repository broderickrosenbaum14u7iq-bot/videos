<?php
/**
 * Integration tests for tube_search_current_query()/tube_search_current_query_display().
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Tests\Integration\Search;

use PHPUnit\Framework\TestCase;

/**
 * Exercises `tube_search_current_query()`/`tube_search_current_query_display()`
 * against a real `get_query_var()`/`set_query_var()` round trip — real
 * WordPress, no HTTP request/rewrite dispatch needed, since
 * `Tube_Search\Search\SearchRouting` only ever hands `tube_search_q` a
 * raw, still-percent-encoded path segment via its rewrite rule's
 * `$matches[1]` capture (documented on `tube_search_current_query()`
 * itself) — `set_query_var()` with that same raw shape reproduces exactly
 * what a real `/search/{query}/` request leaves in place by the time a
 * template reads it.
 *
 * Regression coverage for a real bug found in manual browser testing: the
 * search input's value and the results heading both displayed the raw,
 * still-percent-encoded text (e.g. `tran%20ha%20linh`) instead of decoding
 * it for a human to read (`tran ha linh`) — `tube_search_current_query()`
 * itself was never wrong (its raw value is exactly what
 * `tube_search_query()`'s matching and `rawurlencode()`-built pagination
 * URLs correctly need), the display call sites were simply using it
 * directly instead of through a decoding step.
 */
final class CurrentQueryDisplayIntegrationTest extends TestCase
{
    /**
     * Reset the query var after each test so one test's value can never leak into another's.
     */
    protected function tearDown(): void
    {
        set_query_var('tube_search_q', '');
    }

    /**
     * Outside a search request (no query var set), both functions return ''.
     */
    public function test_both_return_empty_string_outside_a_search_request(): void
    {
        self::assertSame('', tube_search_current_query());
        self::assertSame('', tube_search_current_query_display());
    }

    /**
     * The raw accessor returns the value untouched — it must never
     * decode, since tube_search_query()'s matching and
     * rawurlencode()-built pagination URLs both depend on it staying raw.
     */
    public function test_raw_query_is_never_decoded(): void
    {
        set_query_var('tube_search_q', 'tran%20ha%20linh');

        self::assertSame('tran%20ha%20linh', tube_search_current_query());
    }

    /**
     * ASCII text with encoded spaces (e.g. JS's encodeURIComponent('tran ha linh')) decodes cleanly.
     */
    public function test_display_decodes_ascii_spaces(): void
    {
        set_query_var('tube_search_q', 'tran%20ha%20linh');

        self::assertSame('tran ha linh', tube_search_current_query_display());
    }

    /**
     * Real Vietnamese diacritics, percent-encoded as UTF-8 bytes (what a
     * real browser's encodeURIComponent('trần hà linh') actually
     * produces), decode back to the exact original text.
     */
    public function test_display_decodes_real_utf8_diacritics(): void
    {
        set_query_var('tube_search_q', rawurlencode('trần hà linh'));

        self::assertSame('trần hà linh', tube_search_current_query_display());
    }

    /**
     * A shorter, partial diacritic query decodes correctly too — not just whole-phrase cases.
     */
    public function test_display_decodes_a_partial_query_with_diacritics(): void
    {
        set_query_var('tube_search_q', rawurlencode('clip trần'));

        self::assertSame('clip trần', tube_search_current_query_display());
    }

    /**
     * A single accented word decodes correctly.
     */
    public function test_display_decodes_a_single_accented_word(): void
    {
        set_query_var('tube_search_q', rawurlencode('gái'));

        self::assertSame('gái', tube_search_current_query_display());
    }

    /**
     * Incidental leading/trailing whitespace in the decoded text is
     * trimmed for display — matching what
     * Tube_Search\Search\SearchQuery::search() already trims before
     * matching, so the displayed text is exactly what was searched for.
     */
    public function test_display_trims_leading_and_trailing_whitespace(): void
    {
        set_query_var('tube_search_q', rawurlencode('  tran ha linh  '));

        self::assertSame('tran ha linh', tube_search_current_query_display());
    }

    /**
     * A visitor who searches for text that itself contains a literal
     * "%20" (e.g. a product/version code, not a space) must get that
     * exact text back — not a corrupted extra decode. `encodeURIComponent('50%20')`
     * (what the search form's JS would actually send for this literal
     * text) produces the wire value `50%2520` (the `%` itself becomes
     * `%25`; the digits `20` are untouched). One `rawurldecode()` pass
     * correctly recovers `50%20`; a second, incorrect pass would decode
     * that `%20` into a space, silently turning `50%20` into `50 ` — the
     * exact double-decode corruption this function must never do.
     */
    public function test_display_does_not_double_decode_literal_percent_encoded_looking_text(): void
    {
        set_query_var('tube_search_q', '50%2520');

        self::assertSame('50%20', tube_search_current_query_display());
    }
}
