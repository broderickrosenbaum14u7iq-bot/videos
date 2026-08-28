<?php
/**
 * Unit tests for AdSettings.
 *
 * @package Tube_Ads
 */

declare(strict_types=1);

namespace Tube_Ads\Tests\Unit\Placement;

use PHPUnit\Framework\TestCase;
use Tube_Ads\Placement\AdSettings;
use Tube_Ads\Placement\AdType;
use Tube_Ads\Placement\Placement;
use Tube_Ads\Placement\PrerollFrequency;

/**
 * Exercises AdSettings::from_array()/to_array()/disabled() — no WordPress.
 */
final class AdSettingsTest extends TestCase
{
    /**
     * 2026-08-26 §9 backward-compatibility requirement: an empty/missing
     * option (a fresh install, or this site before tube-ads existed)
     * produces a settings tree where nothing is enabled anywhere.
     */
    public function test_disabled_from_empty_array_enables_nothing(): void
    {
        $settings = AdSettings::from_array([]);

        self::assertFalse($settings->enabled);
        self::assertFalse($settings->preroll->is_active());
        self::assertFalse($settings->global_script->is_active());

        foreach (Placement::configurable_display_placements() as $placement) {
            self::assertFalse($settings->placement($placement)->enabled);
        }

        self::assertTrue(
            $settings->to_array() === AdSettings::disabled()->to_array(),
            'from_array([]) must be identical to disabled().'
        );
    }

    /**
     * A malformed option (wrong types, e.g. a stray string instead of a
     * nested array) never fatals — every value falls back to its safe default.
     */
    public function test_malformed_option_falls_back_safely(): void
    {
        $settings = AdSettings::from_array(
            [
                'enabled'    => 'yes', // Not a bool.
                'preroll'    => 'not-an-array', // Not a nested array.
                'placements' => 'also-not-an-array',
            ]
        );

        self::assertFalse($settings->enabled);
        self::assertFalse($settings->preroll->enabled);
        self::assertFalse($settings->placement(Placement::PlayerAbove)->enabled);
    }

    /**
     * The to_array()/from_array() pair round-trips every field faithfully.
     */
    public function test_round_trip_preserves_configured_values(): void
    {
        $original = AdSettings::from_array(
            [
                'enabled'       => true,
                'debug'         => true,
                'test_mode'     => true,
                'test_vast_url' => 'https://local.test/vast.xml',
                'preroll'       => [
                    'enabled'              => true,
                    'vast_url'             => 'https://ads.example.com/vast.xml',
                    'advertiser_url'       => 'https://advertiser.example.com/landing',
                    'skip_enabled'         => true,
                    'skip_after_seconds'   => 7,
                    'max_duration_seconds' => 45,
                    'timeout_seconds'      => 6,
                    'desktop_enabled'      => true,
                    'mobile_enabled'       => false,
                    'frequency'            => 'once_per_session',
                    'frequency_minutes'    => 15,
                ],
                'placements'    => [
                    'player_above' => [
                        'enabled'         => true,
                        'desktop_enabled' => true,
                        'mobile_enabled'  => true,
                        'type'            => 'image_banner',
                        'html'            => '',
                        'image_url'       => 'https://example.com/banner.png',
                        'link_url'        => 'https://example.com/',
                        'alt_text'        => 'Banner',
                        'open_in_new_tab' => true,
                        'starts_at'       => '2026-01-01',
                        'ends_at'         => '2026-12-31',
                        'label'           => 'Q1 campaign',
                        'grid_position'   => 6,
                    ],
                ],
                'global_script' => [
                    'enabled' => true,
                    'code'    => '<script>window.foo=1;</script>',
                ],
            ]
        );

        $round_tripped = AdSettings::from_array($original->to_array());

        self::assertSame($original->to_array(), $round_tripped->to_array());

        $player_above = $round_tripped->placement(Placement::PlayerAbove);
        self::assertTrue($player_above->enabled);
        self::assertSame(AdType::ImageBanner, $player_above->type);
        self::assertSame('https://example.com/banner.png', $player_above->image_url);
        self::assertSame(6, $player_above->grid_position);
        self::assertNotNull($player_above->starts_at);
        self::assertSame('2026-01-01', $player_above->starts_at->format('Y-m-d'));

        self::assertSame(PrerollFrequency::OncePerSession, $round_tripped->preroll->frequency);
        self::assertSame(7, $round_tripped->preroll->skip_after_seconds);
        self::assertSame('https://advertiser.example.com/landing', $round_tripped->preroll->advertiser_url);
    }

    /**
     * 2026-08-27 advertiser-click task: `advertiser_url` defaults to
     * empty (never a same-site fallback) when missing/malformed, the
     * same "corrupt or partial option never fatals" posture as every
     * other field here.
     */
    public function test_advertiser_url_defaults_to_empty(): void
    {
        self::assertSame('', AdSettings::from_array([])->preroll->advertiser_url);
        self::assertSame(
            '',
            AdSettings::from_array(['preroll' => ['advertiser_url' => 123]])->preroll->advertiser_url
        );
    }

    /**
     * 2026-08-27 re-audit §2/§6: the admin-saved `skip_after_seconds`
     * must reach `AdSettings::preroll->skip_after_seconds` unchanged for
     * every value the admin UI exposes (0-300) -- this is the PHP half
     * of the pipeline the live re-audit traced end to end; the actual
     * defect found there was a stale BROWSER-cached copy of
     * tube-ads-preroll.js (fixed in Tube_Ads\Plugin::asset_version()),
     * not a break in this data path, which was already correct.
     */
    public function test_skip_after_seconds_reaches_config_for_every_admin_exposed_value(): void
    {
        foreach ([0, 3, 5, 8, 10, 30, 300] as $seconds) {
            $settings = AdSettings::from_array(['preroll' => ['skip_after_seconds' => $seconds]]);

            self::assertSame($seconds, $settings->preroll->skip_after_seconds);
        }
    }

    /**
     * 2026-08-27 re-audit §3: the previous cleanup only cleared
     * `player_above`; this asserts the SAME disabled/empty -> no-content
     * contract at the exact keyed-lookup path (`AdSettings::placement()`)
     * for every placement the re-audit found still carrying QA fixture
     * text (`player_below`, `homepage_top`), not just the pure
     * `PlacementConfig` object `PlacementConfigTest` already covers.
     */
    public function test_disabled_or_empty_placements_have_no_content_by_key(): void
    {
        $settings = AdSettings::from_array(
            [
                'placements' => [
                    'player_below' => [
                        'enabled' => false,
                        'type'    => 'custom_html',
                        'html'    => 'PLAYER BELOW AD SLOT',
                    ],
                    'homepage_top' => [
                        'enabled' => true,
                        'type'    => 'custom_html',
                        'html'    => '',
                    ],
                ],
            ]
        );

        self::assertFalse($settings->placement(Placement::PlayerBelow)->enabled);
        self::assertFalse($settings->placement(Placement::HomepageTop)->has_content());
    }

    /**
     * 2026-08-27 "Configurable Skip After": 0 is a real, meaningful
     * sentinel (a mandatory, non-skippable ad — see
     * assets/js/tube-ads-preroll.js's own precedence-rule comment), not
     * an absent value that should fall back to the int default of 5 --
     * guards against a falsy-value regression (e.g. `$data[...] ?: 5`)
     * silently turning "mandatory" back into "skippable after 5s".
     */
    public function test_skip_after_seconds_zero_is_preserved_not_defaulted(): void
    {
        $settings = AdSettings::from_array(
            ['preroll' => ['skip_after_seconds' => 0]]
        );

        self::assertSame(0, $settings->preroll->skip_after_seconds);
    }

    /**
     * 2026-08-26 §23 test mode: effective_vast_url() swaps to the test
     * URL only when test_mode is on, otherwise it's the real configured tag.
     */
    public function test_effective_vast_url_honors_test_mode(): void
    {
        $live = AdSettings::from_array(
            [
                'test_mode' => false,
                'preroll'   => ['vast_url' => 'https://real.example.com/vast.xml'],
            ]
        );
        self::assertSame('https://real.example.com/vast.xml', $live->effective_vast_url());

        $testing = AdSettings::from_array(
            [
                'test_mode'     => true,
                'test_vast_url' => 'https://local.test/vast.xml',
                'preroll'       => ['vast_url' => 'https://real.example.com/vast.xml'],
            ]
        );
        self::assertSame('https://local.test/vast.xml', $testing->effective_vast_url());
    }
}
