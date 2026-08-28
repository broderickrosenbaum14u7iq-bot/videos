<?php
/**
 * The stable set of ad placement identifiers this system supports.
 *
 * @package Tube_Ads
 */

declare(strict_types=1);

namespace Tube_Ads\Placement;

/**
 * The stable set of ad placement identifiers this system supports — a
 * backed enum, the same convention `Tube_Core\Video\CfStreamStatus`/
 * `Tube_Player\Video\ImageSize`/`Tube_Search\Index\CandidateColumn`
 * already use for a fixed, known-in-advance set of string values.
 * `player_preroll` and `global_custom_script` are handled by their own
 * dedicated config shapes (`PrerollConfig`, a bare on/off + textarea) —
 * every other case uses the shared `PlacementConfig` shape.
 */
enum Placement: string
{
    case PlayerPreroll           = 'player_preroll';
    case PlayerAbove             = 'player_above';
    case PlayerBelow             = 'player_below';
    case WatchSidebarTop         = 'watch_sidebar_top';
    case WatchSidebarMiddle      = 'watch_sidebar_middle';
    case RelatedGrid             = 'related_grid';
    case HomepageTop             = 'homepage_top';
    case HomepageBetweenSections = 'homepage_between_sections';
    case FooterBanner            = 'footer_banner';
    case GlobalCustomScript      = 'global_custom_script';

    /**
     * A human-readable label for this placement, for the admin screen.
     */
    public function label(): string
    {
        return match ($this) {
            self::PlayerPreroll => __('Pre-roll (VAST)', 'tube-ads'),
            self::PlayerAbove => __('Player Above', 'tube-ads'),
            self::PlayerBelow => __('Player Below', 'tube-ads'),
            self::WatchSidebarTop => __('Watch Page Sidebar (Top)', 'tube-ads'),
            self::WatchSidebarMiddle => __('Watch Page Sidebar (Middle)', 'tube-ads'),
            self::RelatedGrid => __('Related Video Grid', 'tube-ads'),
            self::HomepageTop => __('Homepage Top', 'tube-ads'),
            self::HomepageBetweenSections => __('Homepage Between Sections', 'tube-ads'),
            self::FooterBanner => __('Footer Banner', 'tube-ads'),
            self::GlobalCustomScript => __('Global Custom Script', 'tube-ads'),
        };
    }

    /**
     * Every placement that uses the shared `PlacementConfig` shape —
     * everything except `PlayerPreroll` (its own `PrerollConfig`) and
     * `GlobalCustomScript` (a bare enabled + code pair, no device/
     * schedule targeting — it's meant for site-wide `<script>` tags, not
     * a positioned creative).
     *
     * @return list<self>
     */
    public static function configurable_display_placements(): array
    {
        return [
            self::PlayerAbove,
            self::PlayerBelow,
            self::WatchSidebarTop,
            self::WatchSidebarMiddle,
            self::RelatedGrid,
            self::HomepageTop,
            self::HomepageBetweenSections,
            self::FooterBanner,
        ];
    }
}
