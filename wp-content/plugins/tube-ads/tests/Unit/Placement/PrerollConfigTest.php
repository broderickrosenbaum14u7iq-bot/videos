<?php
/**
 * Unit tests for PrerollConfig.
 *
 * @package Tube_Ads
 */

declare(strict_types=1);

namespace Tube_Ads\Tests\Unit\Placement;

use PHPUnit\Framework\TestCase;
use Tube_Ads\Placement\PrerollConfig;
use Tube_Ads\Placement\PrerollFrequency;

/**
 * Exercises PrerollConfig's pure decision logic — no WordPress.
 */
final class PrerollConfigTest extends TestCase
{
    /**
     * The disabled() default never has a VAST tag and is never active.
     */
    public function test_disabled_default(): void
    {
        $config = PrerollConfig::disabled();

        self::assertFalse($config->has_vast_tag());
        self::assertFalse($config->is_active());
    }

    /**
     * Enabled + a real VAST URL + at least one device targeted = active.
     */
    public function test_active_requires_enabled_vast_tag_and_a_targeted_device(): void
    {
        $active = $this->config(enabled: true, vast_url: 'https://ads.example.com/vast.xml');
        self::assertTrue($active->is_active());
        self::assertTrue($active->has_vast_tag());

        $no_tag = $this->config(enabled: true, vast_url: '  ');
        self::assertFalse($no_tag->is_active());

        $disabled = $this->config(enabled: false, vast_url: 'https://ads.example.com/vast.xml');
        self::assertFalse($disabled->is_active());

        $no_device = $this->config(
            enabled: true,
            vast_url: 'https://ads.example.com/vast.xml',
            desktop: false,
            mobile: false
        );
        self::assertFalse($no_device->is_active());
    }

    /**
     * Device targeting mirrors PlacementConfig's own semantics.
     */
    public function test_device_targeting(): void
    {
        $desktop_only = $this->config(desktop: true, mobile: false);

        self::assertTrue($desktop_only->is_enabled_for_device(true));
        self::assertFalse($desktop_only->is_enabled_for_device(false));
    }

    /**
     * Build a PrerollConfig with sensible defaults, overriding only what a test cares about.
     *
     * @param bool   $enabled  Whether pre-roll is enabled.
     * @param string $vast_url The VAST tag URL.
     * @param bool   $desktop  Whether it's desktop-enabled.
     * @param bool   $mobile   Whether it's mobile-enabled.
     */
    private function config(
        bool $enabled = true,
        string $vast_url = 'https://ads.example.com/vast.xml',
        bool $desktop = true,
        bool $mobile = true
    ): PrerollConfig {
        return new PrerollConfig(
            enabled: $enabled,
            vast_url: $vast_url,
            advertiser_url: '',
            skip_enabled: true,
            skip_after_seconds: 5,
            max_duration_seconds: 60,
            timeout_seconds: 8,
            desktop_enabled: $desktop,
            mobile_enabled: $mobile,
            frequency: PrerollFrequency::EveryPlay,
            frequency_minutes: 30
        );
    }
}
