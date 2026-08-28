<?php
/**
 * The pre-roll VAST placement's stored configuration.
 *
 * @package Tube_Ads
 */

declare(strict_types=1);

namespace Tube_Ads\Placement;

/**
 * The pre-roll VAST placement's stored configuration — pure data + pure
 * decision logic, no WordPress calls, fully unit-tested. VAST fetching
 * and parsing themselves happen entirely client-side
 * (`assets/js/tube-ads-vast.js`), triggered only by a real play click
 * (2026-08-26 §20 performance requirement — no VAST request on page
 * load); this class only carries the settings that request needs.
 */
final class PrerollConfig
{
    /**
     * Construct the pre-roll configuration.
     *
     * @param bool             $enabled              Whether pre-roll is active at all.
     * @param string           $vast_url             The VAST tag URL. Empty means nothing to request.
     * @param string           $advertiser_url       Manual advertiser landing-page URL, used only when the
     *                                                resolved VAST ad has no (valid) ClickThrough of its own.
     * @param bool             $skip_enabled         Whether a skip control is offered.
     * @param int              $skip_after_seconds   Seconds before skip becomes available.
     * @param int              $max_duration_seconds Hard ceiling on ad playback, regardless of its reported duration.
     * @param int              $timeout_seconds      Seconds to wait for the VAST request/parse before failing open.
     * @param bool             $desktop_enabled      Whether pre-roll is eligible on desktop (>=1024px).
     * @param bool             $mobile_enabled       Whether pre-roll is eligible on mobile (<1024px).
     * @param PrerollFrequency $frequency            How often pre-roll is eligible to show.
     * @param int              $frequency_minutes    The N in "once every N minutes", when `$frequency` is that case.
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $vast_url,
        public readonly string $advertiser_url,
        public readonly bool $skip_enabled,
        public readonly int $skip_after_seconds,
        public readonly int $max_duration_seconds,
        public readonly int $timeout_seconds,
        public readonly bool $desktop_enabled,
        public readonly bool $mobile_enabled,
        public readonly PrerollFrequency $frequency,
        public readonly int $frequency_minutes
    ) {
    }

    /**
     * Whether pre-roll has a real VAST tag to request — an enabled
     * pre-roll with an empty VAST URL has nothing to show.
     */
    public function has_vast_tag(): bool
    {
        return '' !== trim($this->vast_url);
    }

    /**
     * Whether pre-roll is active at all for either device — the
     * server-side gate this project's `wp_footer` hook uses to decide
     * whether to enqueue the pre-roll JS/config in the first place
     * (2026-08-26 §20: no JS at all when disabled).
     */
    public function is_active(): bool
    {
        return $this->enabled && $this->has_vast_tag() && ($this->desktop_enabled || $this->mobile_enabled);
    }

    /**
     * Whether pre-roll targets the given device.
     *
     * @param bool $is_desktop Whether the current device is desktop (>=1024px).
     */
    public function is_enabled_for_device(bool $is_desktop): bool
    {
        return $is_desktop ? $this->desktop_enabled : $this->mobile_enabled;
    }

    /**
     * A safe, inert default — used when the stored option is missing or
     * malformed, so a fresh install never requests a VAST tag.
     */
    public static function disabled(): self
    {
        return new self(
            enabled: false,
            vast_url: '',
            advertiser_url: '',
            skip_enabled: true,
            skip_after_seconds: 5,
            max_duration_seconds: 60,
            timeout_seconds: 8,
            desktop_enabled: true,
            mobile_enabled: true,
            frequency: PrerollFrequency::EveryPlay,
            frequency_minutes: 30
        );
    }
}
