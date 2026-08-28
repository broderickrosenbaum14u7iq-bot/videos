<?php
/**
 * "Other Placements" tab — see screen.php for why every tab's fields render every time.
 *
 * @package Tube_Ads
 *
 * @var AdSettings $tube_ads_settings
 */

declare(strict_types=1);

use Tube_Ads\Placement\AdSettings;
use Tube_Ads\Placement\Placement;

$tube_ads_other_placements = [
    Placement::WatchSidebarTop,
    Placement::WatchSidebarMiddle,
    Placement::RelatedGrid,
    Placement::FooterBanner,
];

foreach ($tube_ads_other_placements as $tube_ads_placement) {
    $tube_ads_config             = $tube_ads_settings->placement($tube_ads_placement);
    $tube_ads_show_grid_position = Placement::RelatedGrid === $tube_ads_placement;

    require __DIR__ . '/partial-placement-fields.php';
}
