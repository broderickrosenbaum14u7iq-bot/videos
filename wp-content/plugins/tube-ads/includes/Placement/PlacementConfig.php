<?php
/**
 * One display placement's stored configuration.
 *
 * @package Tube_Ads
 */

declare(strict_types=1);

namespace Tube_Ads\Placement;

use DateTimeImmutable;

/**
 * One display placement's stored configuration — pure data + pure
 * decision logic, no WordPress calls, fully unit-tested. Sanitizing raw
 * admin input into these values is a separate, WordPress-coupled
 * concern (`Tube_Ads\Admin\SettingsSanitizer`), the same "PageMeta is
 * never pre-escaped/pre-sanitized, that's the renderer's job" split
 * `Tube_Seo\Meta\PageMeta` already established for this project.
 */
final class PlacementConfig
{
    /**
     * Construct one placement's configuration.
     *
     * @param bool               $enabled          Whether this placement is active at all.
     * @param bool               $desktop_enabled  Whether it shows on desktop (>=1024px, this project's breakpoint).
     * @param bool               $mobile_enabled   Whether it shows on mobile (<1024px).
     * @param AdType             $type             Which kind of creative this placement renders.
     * @param string             $html             Raw HTML/JS, when `$type` is `AdType::CustomHtml`.
     * @param string             $image_url        The banner image URL, when `$type` is `AdType::ImageBanner`.
     * @param string             $link_url         The banner's destination URL, when `$type` is `AdType::ImageBanner`.
     * @param string             $alt_text         The banner's `alt` text, when `$type` is `AdType::ImageBanner`.
     * @param bool               $open_in_new_tab  Whether the banner link opens in a new tab.
     * @param ?DateTimeImmutable $starts_at Optional direct-sold campaign start (inclusive), or null for "always".
     * @param ?DateTimeImmutable $ends_at   Optional direct-sold campaign end (inclusive), or null for "always".
     * @param string             $label            An optional operator-facing note (never rendered to visitors).
     * @param int                $grid_position    For `Placement::RelatedGrid` only: insert after this many cards.
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly bool $desktop_enabled,
        public readonly bool $mobile_enabled,
        public readonly AdType $type,
        public readonly string $html,
        public readonly string $image_url,
        public readonly string $link_url,
        public readonly string $alt_text,
        public readonly bool $open_in_new_tab,
        public readonly ?DateTimeImmutable $starts_at,
        public readonly ?DateTimeImmutable $ends_at,
        public readonly string $label,
        public readonly int $grid_position
    ) {
    }

    /**
     * Whether this placement should render at all right now: enabled,
     * targeted to the requesting device, and (for a scheduled direct-sold
     * banner) within its date window.
     *
     * @param bool              $is_desktop Whether the current device is desktop (>=1024px).
     * @param DateTimeImmutable $now The current moment, for schedule evaluation.
     */
    public function is_active(bool $is_desktop, DateTimeImmutable $now): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if (! $this->is_enabled_for_device($is_desktop)) {
            return false;
        }

        return $this->is_within_schedule($now);
    }

    /**
     * Whether this placement targets the given device.
     *
     * @param bool $is_desktop Whether the current device is desktop (>=1024px).
     */
    public function is_enabled_for_device(bool $is_desktop): bool
    {
        return $is_desktop ? $this->desktop_enabled : $this->mobile_enabled;
    }

    /**
     * Whether `$now` falls within this placement's optional start/end
     * date window — a placement with neither date set is always active
     * (an ordinary provider ad-tag, not a scheduled campaign).
     *
     * @param DateTimeImmutable $now The current moment.
     */
    public function is_within_schedule(DateTimeImmutable $now): bool
    {
        if (null !== $this->starts_at && $now < $this->starts_at) {
            return false;
        }

        if (null !== $this->ends_at && $now > $this->ends_at) {
            return false;
        }

        return true;
    }

    /**
     * Whether this configuration has real, renderable content — an
     * enabled placement with an empty custom-HTML string or no image URL
     * has nothing to show, the same "empty means nothing renders, never
     * a placeholder" posture as everywhere else in this project.
     */
    public function has_content(): bool
    {
        return match ($this->type) {
            AdType::CustomHtml => '' !== trim($this->html),
            AdType::ImageBanner => '' !== trim($this->image_url),
        };
    }

    /**
     * A placement with every field at its safe, inert default — used
     * when a stored option is missing or malformed, so a fresh install
     * (or a clone with no ads configured yet) never renders anything.
     */
    public static function disabled(): self
    {
        return new self(
            enabled: false,
            desktop_enabled: true,
            mobile_enabled: true,
            type: AdType::CustomHtml,
            html: '',
            image_url: '',
            link_url: '',
            alt_text: '',
            open_in_new_tab: false,
            starts_at: null,
            ends_at: null,
            label: '',
            grid_position: 4
        );
    }
}
