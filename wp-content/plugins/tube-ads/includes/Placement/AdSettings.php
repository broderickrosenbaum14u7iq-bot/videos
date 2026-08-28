<?php
/**
 * The full tube-ads configuration tree.
 *
 * @package Tube_Ads
 */

declare(strict_types=1);

namespace Tube_Ads\Placement;

use DateTimeImmutable;

/**
 * The full tube-ads configuration tree — one WordPress option
 * (`tube_ads_settings`, a nested array) hydrated into typed, pure value
 * objects. `self::from_array()` never calls a WordPress sanitize
 * function itself: the array it's given is assumed already-sanitized
 * (`Tube_Ads\Admin\SettingsSanitizer` is the one place that happens, the
 * same "sanitize once, on the way into storage" split
 * `Tube_Seo\Meta\PageMetaBuilder` already established for this project)
 * — this class only ever defensively type-checks/defaults a malformed
 * or missing value, so a corrupt or partial option never fatals, it
 * just falls back to `PlacementConfig::disabled()`/`PrerollConfig::disabled()`.
 */
final class AdSettings
{
    /**
     * Construct the full settings tree from already-typed parts.
     *
     * @param bool                           $enabled       The global ads on/off switch.
     * @param bool                           $debug         Whether to emit console debug logging client-side.
     * @param bool                           $test_mode     Local test mode (§23) — swaps in `$test_vast_url`.
     * @param string                         $test_vast_url A local/static VAST URL, used only when `$test_mode` is on.
     * @param PrerollConfig                  $preroll       Pre-roll settings.
     * @param array<string, PlacementConfig> $placements    Keyed by `Placement::value`.
     * @param GlobalScriptConfig             $global_script Site-wide custom script settings.
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly bool $debug,
        public readonly bool $test_mode,
        public readonly string $test_vast_url,
        public readonly PrerollConfig $preroll,
        public readonly array $placements,
        public readonly GlobalScriptConfig $global_script
    ) {
    }

    /**
     * The VAST URL to actually use for pre-roll right now — the real
     * configured tag, or the test tag when Test Mode is on (§23: local
     * verification only, never fabricates production metrics since it
     * simply swaps which URL client-side code requests).
     */
    public function effective_vast_url(): string
    {
        return $this->test_mode ? trim($this->test_vast_url) : $this->preroll->vast_url;
    }

    /**
     * One placement's configuration, or a safe disabled default if it's
     * missing from `$placements` or isn't a display placement.
     *
     * @param Placement $placement Which placement to read.
     */
    public function placement(Placement $placement): PlacementConfig
    {
        $config = $this->placements[ $placement->value ] ?? null;

        return $config instanceof PlacementConfig ? $config : PlacementConfig::disabled();
    }

    /**
     * Hydrate from an already-sanitized array (see this class's own
     * docblock for why sanitization itself never happens here).
     *
     * @param array<array-key, mixed> $data The stored (or freshly sanitized) option value.
     */
    public static function from_array(array $data): self
    {
        $placements = [];

        foreach (Placement::configurable_display_placements() as $placement) {
            $placements[ $placement->value ] = self::placement_from_array(
                self::array_value($data, ['placements', $placement->value])
            );
        }

        return new self(
            enabled: self::bool_value($data, ['enabled'], false),
            debug: self::bool_value($data, ['debug'], false),
            test_mode: self::bool_value($data, ['test_mode'], false),
            test_vast_url: self::string_value($data, ['test_vast_url'], ''),
            preroll: self::preroll_from_array(self::array_value($data, ['preroll'])),
            placements: $placements,
            global_script: self::global_script_from_array(self::array_value($data, ['global_script']))
        );
    }

    /**
     * The inverse of self::from_array() — a plain array shape suitable
     * for `update_option()`.
     *
     * @return array<string, mixed>
     */
    public function to_array(): array
    {
        $placements = [];

        foreach ($this->placements as $key => $config) {
            $placements[ $key ] = [
                'enabled'         => $config->enabled,
                'desktop_enabled' => $config->desktop_enabled,
                'mobile_enabled'  => $config->mobile_enabled,
                'type'            => $config->type->value,
                'html'            => $config->html,
                'image_url'       => $config->image_url,
                'link_url'        => $config->link_url,
                'alt_text'        => $config->alt_text,
                'open_in_new_tab' => $config->open_in_new_tab,
                'starts_at'       => $config->starts_at?->format('Y-m-d'),
                'ends_at'         => $config->ends_at?->format('Y-m-d'),
                'label'           => $config->label,
                'grid_position'   => $config->grid_position,
            ];
        }

        return [
            'enabled'       => $this->enabled,
            'debug'         => $this->debug,
            'test_mode'     => $this->test_mode,
            'test_vast_url' => $this->test_vast_url,
            'preroll'       => [
                'enabled'              => $this->preroll->enabled,
                'vast_url'             => $this->preroll->vast_url,
                'advertiser_url'       => $this->preroll->advertiser_url,
                'skip_enabled'         => $this->preroll->skip_enabled,
                'skip_after_seconds'   => $this->preroll->skip_after_seconds,
                'max_duration_seconds' => $this->preroll->max_duration_seconds,
                'timeout_seconds'      => $this->preroll->timeout_seconds,
                'desktop_enabled'      => $this->preroll->desktop_enabled,
                'mobile_enabled'       => $this->preroll->mobile_enabled,
                'frequency'            => $this->preroll->frequency->value,
                'frequency_minutes'    => $this->preroll->frequency_minutes,
            ],
            'placements'    => $placements,
            'global_script' => [
                'enabled' => $this->global_script->enabled,
                'code'    => $this->global_script->code,
            ],
        ];
    }

    /**
     * A fully-disabled settings tree — used when the option has never
     * been saved, so a fresh install (or a clone with no ads configured
     * yet) renders nothing anywhere (2026-08-26 §9/§26 backward-
     * compatibility/cloning requirement).
     */
    public static function disabled(): self
    {
        return self::from_array([]);
    }

    /**
     * Hydrate the pre-roll configuration from its already-sanitized sub-array.
     *
     * @param array<array-key, mixed> $data The pre-roll slice of the stored option.
     */
    private static function preroll_from_array(array $data): PrerollConfig
    {
        $frequency = PrerollFrequency::tryFrom(self::string_value($data, ['frequency'], ''))
            ?? PrerollFrequency::EveryPlay;

        return new PrerollConfig(
            enabled: self::bool_value($data, ['enabled'], false),
            vast_url: self::string_value($data, ['vast_url'], ''),
            advertiser_url: self::string_value($data, ['advertiser_url'], ''),
            skip_enabled: self::bool_value($data, ['skip_enabled'], true),
            skip_after_seconds: self::int_value($data, ['skip_after_seconds'], 5),
            max_duration_seconds: self::int_value($data, ['max_duration_seconds'], 60),
            timeout_seconds: self::int_value($data, ['timeout_seconds'], 8),
            desktop_enabled: self::bool_value($data, ['desktop_enabled'], true),
            mobile_enabled: self::bool_value($data, ['mobile_enabled'], true),
            frequency: $frequency,
            frequency_minutes: self::int_value($data, ['frequency_minutes'], 30)
        );
    }

    /**
     * Hydrate one placement's configuration from its already-sanitized sub-array.
     *
     * @param array<array-key, mixed> $data One placement's slice of the stored option.
     */
    private static function placement_from_array(array $data): PlacementConfig
    {
        $type = AdType::tryFrom(self::string_value($data, ['type'], '')) ?? AdType::CustomHtml;

        return new PlacementConfig(
            enabled: self::bool_value($data, ['enabled'], false),
            desktop_enabled: self::bool_value($data, ['desktop_enabled'], true),
            mobile_enabled: self::bool_value($data, ['mobile_enabled'], true),
            type: $type,
            html: self::string_value($data, ['html'], ''),
            image_url: self::string_value($data, ['image_url'], ''),
            link_url: self::string_value($data, ['link_url'], ''),
            alt_text: self::string_value($data, ['alt_text'], ''),
            open_in_new_tab: self::bool_value($data, ['open_in_new_tab'], false),
            starts_at: self::date_value($data, ['starts_at']),
            ends_at: self::date_value($data, ['ends_at']),
            label: self::string_value($data, ['label'], ''),
            grid_position: max(1, self::int_value($data, ['grid_position'], 4))
        );
    }

    /**
     * Hydrate the global custom script's configuration from its already-sanitized sub-array.
     *
     * @param array<array-key, mixed> $data The global-script slice of the stored option.
     */
    private static function global_script_from_array(array $data): GlobalScriptConfig
    {
        return new GlobalScriptConfig(
            enabled: self::bool_value($data, ['enabled'], false),
            code: self::string_value($data, ['code'], '')
        );
    }

    /**
     * Read a nested array value, defensively — never assumes the shape is correct.
     *
     * @param array<array-key, mixed> $data The array to read from.
     * @param string[]                $path One or more nested keys.
     *
     * @return array<array-key, mixed>
     */
    private static function array_value(array $data, array $path): array
    {
        $value = self::dig($data, $path);

        return is_array($value) ? $value : [];
    }

    /**
     * Read a nested bool value, defensively.
     *
     * @param array<array-key, mixed> $data     The array to read from.
     * @param string[]                $path     One or more nested keys.
     * @param bool                    $fallback The value to use if the path is missing or isn't a bool.
     */
    private static function bool_value(array $data, array $path, bool $fallback): bool
    {
        $value = self::dig($data, $path);

        return is_bool($value) ? $value : $fallback;
    }

    /**
     * Read a nested string value, defensively.
     *
     * @param array<array-key, mixed> $data     The array to read from.
     * @param string[]                $path     One or more nested keys.
     * @param string                  $fallback The value to use if the path is missing or isn't a string.
     */
    private static function string_value(array $data, array $path, string $fallback): string
    {
        $value = self::dig($data, $path);

        return is_string($value) ? $value : $fallback;
    }

    /**
     * Read a nested int value, defensively.
     *
     * @param array<array-key, mixed> $data     The array to read from.
     * @param string[]                $path     One or more nested keys.
     * @param int                     $fallback The value to use if the path is missing or isn't an int.
     */
    private static function int_value(array $data, array $path, int $fallback): int
    {
        $value = self::dig($data, $path);

        return is_int($value) ? $value : $fallback;
    }

    /**
     * Read a nested `Y-m-d` date string, defensively.
     *
     * @param array<array-key, mixed> $data The array to read from.
     * @param string[]                $path One or more nested keys.
     */
    private static function date_value(array $data, array $path): ?DateTimeImmutable
    {
        $value = self::dig($data, $path);

        if (! is_string($value) || '' === $value) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return false === $date ? null : $date;
    }

    /**
     * Walk a nested array by key path, returning null the moment any segment is missing.
     *
     * @param array<array-key, mixed> $data The array to read from.
     * @param string[]                $path One or more nested keys.
     */
    private static function dig(array $data, array $path): mixed
    {
        $current = $data;

        foreach ($path as $key) {
            if (! is_array($current) || ! array_key_exists($key, $current)) {
                return null;
            }

            $current = $current[ $key ];
        }

        return $current;
    }
}
