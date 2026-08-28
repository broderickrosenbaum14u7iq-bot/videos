<?php
/**
 * "Player Ads" tab — see screen.php for why every tab's fields render every time.
 *
 * @package Tube_Ads
 *
 * @var AdSettings $tube_ads_settings
 */

declare(strict_types=1);

use Tube_Ads\Placement\AdSettings;
use Tube_Ads\Placement\Placement;

foreach ([Placement::PlayerAbove, Placement::PlayerBelow] as $tube_ads_placement) {
    $tube_ads_config             = $tube_ads_settings->placement($tube_ads_placement);
    $tube_ads_show_grid_position = false;

    require __DIR__ . '/partial-placement-fields.php';
}
