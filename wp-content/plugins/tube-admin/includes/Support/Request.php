<?php
/**
 * A small, safe accessor for reading string values out of $_GET/$_POST.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Support;

/**
 * A small, safe accessor for reading string values out of $_GET/$_POST.
 *
 * Every screen in this plugin reads request query args/form fields
 * (status filters, pagination, item IDs); PHPStan level `max` correctly
 * rejects casting `$_GET`/`$_POST`'s `mixed` values directly to `string`
 * (a cast can't safely handle the array/object case), so this is a real,
 * multiple-call-site utility — not speculative — that narrows via
 * `is_string()` instead of a cast. Never itself sanitizes/escapes;
 * callers still run the result through `sanitize_key()`/`absint()`/
 * `esc_html()` etc. as appropriate for how the value is used.
 */
final class Request
{
    /**
     * Read a string value from a request superglobal, or a default if missing/non-string.
     *
     * @param array<array-key, mixed> $source   Typically $_GET or $_POST.
     * @param string                  $key      The field name.
     * @param string                  $fallback Returned if the key is absent or not a string.
     */
    public static function string(array $source, string $key, string $fallback = ''): string
    {
        $value = $source[ $key ] ?? $fallback;

        return is_string($value) ? $value : $fallback;
    }
}
