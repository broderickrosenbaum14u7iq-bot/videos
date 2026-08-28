<?php
/**
 * Sanitizes raw admin-submitted settings before they're stored.
 *
 * @package Tube_Ads
 */

declare(strict_types=1);

namespace Tube_Ads\Admin;

use Tube_Ads\Placement\Placement;

/**
 * Sanitizes the raw `$_POST['tube_ads_settings']` array `options.php`
 * hands to this class's `sanitize()` (registered as
 * `register_setting()`'s `sanitize_callback` — WordPress itself already
 * verified the settings-page nonce and the `manage_options` capability
 * before ever calling it, per `register_setting()`'s own contract) into
 * the shape `Tube_Ads\Placement\AdSettings::from_array()` expects.
 *
 * The one deliberate exception to normal sanitization (2026-08-26 §5):
 * the raw HTML/JS fields (a placement's `html`, the global custom
 * script's `code`) pass through byte-for-byte ONLY for a user with the
 * `unfiltered_html` capability (Administrators, on a non-multisite
 * install — WordPress's own standing rule for who may store raw
 * script/HTML anywhere, e.g. in a Custom HTML block) — `wp_kses_post()`
 * for everyone else, which keeps basic markup (a banner's own `<a>`/
 * `<img>`) but strips `<script>`/inline event handlers, so a
 * lower-privileged user can never inject a script this way even though
 * they can reach this settings screen. Every other field is a normal
 * bool/URL/plain-text value with no such exception.
 */
final class SettingsSanitizer
{
    /**
     * Sanitize one full settings submission.
     *
     * @param mixed $raw Whatever `options.php` passes in — not
     *     guaranteed to be an array (a tampered/malformed request), so
     *     every level of this method is defensive, never assumes shape.
     *
     * @return array<array-key, mixed>
     */
    public function sanitize(mixed $raw): array
    {
        $data               = is_array($raw) ? $raw : [];
        $can_store_raw_code = current_user_can('unfiltered_html');

        $placements = [];

        foreach (Placement::configurable_display_placements() as $placement) {
            $placements[ $placement->value ] = $this->sanitize_placement(
                self::sub_array($data, ['placements', $placement->value]),
                $can_store_raw_code
            );
        }

        return [
            'enabled'       => self::bool($data, 'enabled'),
            'debug'         => self::bool($data, 'debug'),
            'test_mode'     => self::bool($data, 'test_mode'),
            'test_vast_url' => self::test_vast_url($data, 'test_vast_url'),
            'preroll'       => $this->sanitize_preroll(self::sub_array($data, ['preroll'])),
            'placements'    => $placements,
            'global_script' => [
                'enabled' => self::bool(self::sub_array($data, ['global_script']), 'enabled'),
                'code'    => $this->sanitize_code(
                    self::string(self::sub_array($data, ['global_script']), 'code'),
                    $can_store_raw_code
                ),
            ],
        ];
    }

    /**
     * Sanitize the pre-roll sub-array.
     *
     * @param array<array-key, mixed> $data The raw pre-roll input.
     *
     * @return array<array-key, mixed>
     */
    private function sanitize_preroll(array $data): array
    {
        return [
            'enabled'              => self::bool($data, 'enabled'),
            'vast_url'             => self::url($data, 'vast_url'),
            'advertiser_url'       => self::advertiser_url($data, 'advertiser_url'),
            'skip_enabled'         => self::bool($data, 'skip_enabled'),
            'skip_after_seconds'   => self::int_between($data, 'skip_after_seconds', 0, 300),
            'max_duration_seconds' => self::int_between($data, 'max_duration_seconds', 1, 600),
            'timeout_seconds'      => self::int_between($data, 'timeout_seconds', 1, 60),
            'desktop_enabled'      => self::bool($data, 'desktop_enabled'),
            'mobile_enabled'       => self::bool($data, 'mobile_enabled'),
            'frequency'            => self::enum(
                $data,
                'frequency',
                ['every_play', 'once_per_session', 'every_n_minutes']
            ),
            'frequency_minutes'    => self::int_between($data, 'frequency_minutes', 1, 1440),
        ];
    }

    /**
     * Sanitize one display placement's sub-array.
     *
     * @param array<array-key, mixed> $data               The raw placement input.
     * @param bool                    $can_store_raw_code  Whether the current user may store raw HTML/JS verbatim.
     *
     * @return array<array-key, mixed>
     */
    private function sanitize_placement(array $data, bool $can_store_raw_code): array
    {
        return [
            'enabled'         => self::bool($data, 'enabled'),
            'desktop_enabled' => self::bool($data, 'desktop_enabled'),
            'mobile_enabled'  => self::bool($data, 'mobile_enabled'),
            'type'            => self::enum($data, 'type', ['custom_html', 'image_banner']),
            'html'            => $this->sanitize_code(self::string($data, 'html'), $can_store_raw_code),
            'image_url'       => self::url($data, 'image_url'),
            'link_url'        => self::url($data, 'link_url'),
            'alt_text'        => sanitize_text_field(self::string($data, 'alt_text')),
            'open_in_new_tab' => self::bool($data, 'open_in_new_tab'),
            'starts_at'       => self::date(self::string($data, 'starts_at')),
            'ends_at'         => self::date(self::string($data, 'ends_at')),
            'label'           => sanitize_text_field(self::string($data, 'label')),
            'grid_position'   => self::int_between($data, 'grid_position', 1, 100),
        ];
    }

    /**
     * Sanitize one raw HTML/JS code field — see this class's own docblock for the capability split.
     *
     * @param string $code               The raw submitted value.
     * @param bool   $can_store_raw_code Whether the current user may store it verbatim.
     */
    private function sanitize_code(string $code, bool $can_store_raw_code): string
    {
        return $can_store_raw_code ? $code : wp_kses_post($code);
    }

    /**
     * Read+trim a `Y-m-d` date string, or `''` if it doesn't match that shape.
     *
     * @param string $value The raw submitted value.
     */
    private function date(string $value): string
    {
        $value = trim($value);

        return 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    /**
     * Read a nested array, defensively.
     *
     * @param array<array-key, mixed> $data The array to read from.
     * @param string[]                $path One or more nested keys.
     *
     * @return array<array-key, mixed>
     */
    private static function sub_array(array $data, array $path): array
    {
        $current = $data;

        foreach ($path as $key) {
            if (! array_key_exists($key, $current) || ! is_array($current[ $key ])) {
                return [];
            }

            $current = $current[ $key ];
        }

        return $current;
    }

    /**
     * A checkbox field's value — present (any value) means checked/true;
     * absent means false. That covers a real HTML checkbox's own
     * submission shape (a raw `$_POST` string '1', or the key missing
     * entirely when unchecked — checkboxes never submit "0"), which is
     * the only shape `sanitize()` ever sees through the real admin form.
     *
     * A native PHP bool is also handled explicitly, not just strings:
     * found live during the 2026-08-27 re-audit -- `sanitize_option()`
     * (the same registered filter `options.php` calls, used here to
     * verify a save end-to-end rather than calling this class directly)
     * was fed an already-hydrated array straight from `get_option()`,
     * where a `false` field is a real PHP bool, not the string '0'. The
     * old `'0' !== $data[$key]` check is true for a bool `false` (they
     * are different types, so `!==` never matches), silently flipping
     * every already-false checkbox back to true. That never happens via
     * the real form (whose values are always strings/absent), but
     * anything else that re-sanitizes an already-typed array -- a
     * future import/export tool, a WP-CLI command -- would hit it.
     *
     * @param array<array-key, mixed> $data The array to read from.
     * @param string                  $key  Which field.
     */
    private static function bool(array $data, string $key): bool
    {
        if (! array_key_exists($key, $data)) {
            return false;
        }

        $value = $data[ $key ];

        if (is_bool($value)) {
            return $value;
        }

        return '' !== $value && '0' !== $value;
    }

    /**
     * A plain string field's value.
     *
     * @param array<array-key, mixed> $data The array to read from.
     * @param string                  $key  Which field.
     */
    private static function string(array $data, string $key): string
    {
        return isset($data[ $key ]) && is_string($data[ $key ]) ? $data[ $key ] : '';
    }

    /**
     * A URL field's value, sanitized via `esc_url_raw()` — never a bare, unsanitized string.
     *
     * @param array<array-key, mixed> $data The array to read from.
     * @param string                  $key  Which field.
     */
    private static function url(array $data, string $key): string
    {
        return esc_url_raw(self::string($data, $key));
    }

    /**
     * The Test VAST URL field's value — like self::url() but also
     * accepts a root-relative path (e.g.
     * `/wp-content/uploads/tube-ads-test/vast.xml`), deliberately, so
     * the one stored value works identically from `localhost` and a LAN
     * IP: the browser resolves a relative URL against whatever host
     * loaded the page (2026-08-27 QA task). `esc_url_raw()` alone
     * already leaves a root-relative path untouched, so it isn't the
     * gap here -- the real admin-UI defect was `<input type="url">`'s
     * OWN native browser-side constraint validation rejecting that same
     * value before the page could ever submit (fixed alongside this, in
     * tab-general.php: that field is `type="text"` now, since browser
     * constraint validation was never a real security boundary anyway
     * -- it does not reject `javascript:`/`data:` either. This method
     * is the actual, only place that validation belongs).
     *
     * A DOUBLE leading slash (`//evil.com/x`) is protocol-relative to
     * whatever host the browser is told, not necessarily this site --
     * `esc_url_raw()` does not reject that (it is an ordinary URL), so
     * it is rejected explicitly here, before the single-slash check
     * below would otherwise also match its leading character.
     *
     * @param array<array-key, mixed> $data The array to read from.
     * @param string                  $key  Which field.
     */
    private static function test_vast_url(array $data, string $key): string
    {
        $value = trim(self::string($data, $key));

        if ('' === $value || str_starts_with($value, '//')) {
            return '';
        }

        if (str_starts_with($value, '/')) {
            // esc_url_raw() expects an absolute URL to parse and would
            // corrupt a bare path, so a root-relative value is
            // validated directly instead: printable, no characters
            // that could break out of the double-quoted HTML attribute
            // this is later output into (defense in depth -- the
            // template's own esc_attr() already does this too).
            return 1 === preg_match('/^\/[^\s<>"\'\\\\]*$/', $value) ? $value : '';
        }

        return self::url($data, $key);
    }

    /**
     * The manual Advertiser URL field's value — unlike
     * self::test_vast_url() above, a root-relative path is deliberately
     * NOT accepted here: this field always represents an EXTERNAL
     * advertiser landing page (opened in a new tab when the ad creative
     * is clicked), never something resolved relative to this site, so
     * there is no dual-host reason to allow it, and allowing it would
     * let this field accidentally resolve to a page on this site
     * itself — exactly the "opens the current site" bug this whole
     * feature exists to prevent (2026-08-27 advertiser-click task).
     *
     * Requires an explicit `http:`/`https:` scheme (an allow-list, not
     * a blocklist) before ever calling `esc_url_raw()` — `esc_url_raw()`
     * alone does not reject `javascript:`/`data:`/`file:` schemes it
     * simply doesn't recognize as one of WordPress's own allowed
     * protocols and normally strips, but a stray protocol added to that
     * allowlist by another plugin/mu-plugin would otherwise slip
     * through; requiring http(s) explicitly here does not depend on
     * that shared, mutable list at all.
     *
     * @param array<array-key, mixed> $data The array to read from.
     * @param string                  $key  Which field.
     */
    private static function advertiser_url(array $data, string $key): string
    {
        $value = trim(self::string($data, $key));

        if ('' === $value) {
            return '';
        }

        $scheme = wp_parse_url($value, PHP_URL_SCHEME);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            return '';
        }

        return esc_url_raw($value);
    }

    /**
     * An integer field's value, clamped to a safe range — never negative/absurd, never a non-numeric string.
     *
     * @param array<array-key, mixed> $data The array to read from.
     * @param string                  $key  Which field.
     * @param int                     $min  The minimum allowed value.
     * @param int                     $max  The maximum allowed value.
     */
    private static function int_between(array $data, string $key, int $min, int $max): int
    {
        $value = isset($data[ $key ]) && is_numeric($data[ $key ]) ? (int) $data[ $key ] : $min;

        return max($min, min($max, $value));
    }

    /**
     * A whitelisted-string field's value — anything not in `$allowed` falls back to the first allowed value.
     *
     * @param array<array-key, mixed> $data    The array to read from.
     * @param string                  $key     Which field.
     * @param string[]                $allowed The only values this field may take.
     */
    private static function enum(array $data, string $key, array $allowed): string
    {
        $value = self::string($data, $key);

        return in_array($value, $allowed, true) ? $value : $allowed[0];
    }
}
