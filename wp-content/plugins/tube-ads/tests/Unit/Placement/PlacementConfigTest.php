<?php
/**
 * Unit tests for PlacementConfig.
 *
 * @package Tube_Ads
 */

declare(strict_types=1);

namespace Tube_Ads\Tests\Unit\Placement;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tube_Ads\Placement\AdType;
use Tube_Ads\Placement\PlacementConfig;

/**
 * Exercises PlacementConfig's pure decision logic — no WordPress.
 */
final class PlacementConfigTest extends TestCase
{
    /**
     * The disabled() default is inert: not enabled, no content.
     */
    public function test_disabled_default_is_inactive(): void
    {
        $config = PlacementConfig::disabled();

        self::assertFalse($config->is_active(true, new DateTimeImmutable()));
        self::assertFalse($config->is_active(false, new DateTimeImmutable()));
        self::assertFalse($config->has_content());
    }

    /**
     * An enabled placement targeted only at desktop is inactive on mobile, and vice versa.
     */
    public function test_device_targeting(): void
    {
        $desktop_only = $this->config(desktop: true, mobile: false);

        self::assertTrue($desktop_only->is_enabled_for_device(true));
        self::assertFalse($desktop_only->is_enabled_for_device(false));

        $mobile_only = $this->config(desktop: false, mobile: true);

        self::assertFalse($mobile_only->is_enabled_for_device(true));
        self::assertTrue($mobile_only->is_enabled_for_device(false));
    }

    /**
     * A placement with no schedule set is always within its window.
     */
    public function test_no_schedule_is_always_active(): void
    {
        $config = $this->config();

        self::assertTrue($config->is_within_schedule(new DateTimeImmutable('2020-01-01')));
        self::assertTrue($config->is_within_schedule(new DateTimeImmutable('2099-01-01')));
    }

    /**
     * A placement is inactive before its start date and after its end date, active between them.
     */
    public function test_schedule_window(): void
    {
        $config = $this->config(
            starts_at: new DateTimeImmutable('2026-01-10'),
            ends_at: new DateTimeImmutable('2026-01-20')
        );

        self::assertFalse($config->is_within_schedule(new DateTimeImmutable('2026-01-09')));
        self::assertTrue($config->is_within_schedule(new DateTimeImmutable('2026-01-15')));
        self::assertFalse($config->is_within_schedule(new DateTimeImmutable('2026-01-21')));
    }

    /**
     * The is_active() check combines enabled + device + schedule — any one failing makes it false.
     */
    public function test_is_active_requires_enabled_device_and_schedule(): void
    {
        $now = new DateTimeImmutable('2026-06-01');

        $everything_ok = $this->config(enabled: true, desktop: true, mobile: true);
        self::assertTrue($everything_ok->is_active(true, $now));

        $disabled = $this->config(enabled: false);
        self::assertFalse($disabled->is_active(true, $now));

        $wrong_device = $this->config(desktop: false, mobile: true);
        self::assertFalse($wrong_device->is_active(true, $now));

        $expired = $this->config(ends_at: new DateTimeImmutable('2025-01-01'));
        self::assertFalse($expired->is_active(true, $now));
    }

    /**
     * The has_content() check is type-aware: custom_html needs non-empty html, image_banner needs a real image_url.
     */
    public function test_has_content_is_type_aware(): void
    {
        $empty_html = $this->config(type: AdType::CustomHtml, html: '   ');
        self::assertFalse($empty_html->has_content());

        $real_html = $this->config(type: AdType::CustomHtml, html: '<div>ad</div>');
        self::assertTrue($real_html->has_content());

        $empty_banner = $this->config(type: AdType::ImageBanner, image_url: '');
        self::assertFalse($empty_banner->has_content());

        $real_banner = $this->config(type: AdType::ImageBanner, image_url: 'https://example.com/ad.png');
        self::assertTrue($real_banner->has_content());
    }

    /**
     * Build a PlacementConfig with sensible defaults, overriding only what a test cares about.
     *
     * @param bool               $enabled   Whether the placement is enabled.
     * @param bool               $desktop   Whether it's desktop-enabled.
     * @param bool               $mobile    Whether it's mobile-enabled.
     * @param AdType             $type      Which creative type.
     * @param string             $html      Raw HTML/JS content.
     * @param string             $image_url Banner image URL.
     * @param ?DateTimeImmutable $starts_at Optional schedule start.
     * @param ?DateTimeImmutable $ends_at   Optional schedule end.
     */
    private function config(
        bool $enabled = true,
        bool $desktop = true,
        bool $mobile = true,
        AdType $type = AdType::CustomHtml,
        string $html = '<div>ad</div>',
        string $image_url = '',
        ?DateTimeImmutable $starts_at = null,
        ?DateTimeImmutable $ends_at = null
    ): PlacementConfig {
        return new PlacementConfig(
            enabled: $enabled,
            desktop_enabled: $desktop,
            mobile_enabled: $mobile,
            type: $type,
            html: $html,
            image_url: $image_url,
            link_url: '',
            alt_text: '',
            open_in_new_tab: false,
            starts_at: $starts_at,
            ends_at: $ends_at,
            label: '',
            grid_position: 4
        );
    }
}
