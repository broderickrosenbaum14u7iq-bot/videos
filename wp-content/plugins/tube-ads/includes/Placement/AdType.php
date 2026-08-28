<?php
/**
 * What kind of creative a display placement renders.
 *
 * @package Tube_Ads
 */

declare(strict_types=1);

namespace Tube_Ads\Placement;

/**
 * What kind of creative a display placement renders — provider-supplied
 * `CustomHtml` (a JS/HTML snippet, e.g. a network's ad-tag script) or a
 * `ImageBanner` (a direct-sold image creative with its own link/alt/
 * schedule — 2026-08-26 direct-sold-inventory requirement). Every
 * configurable display placement (`Placement::configurable_display_placements()`)
 * picks one; there is no third "both" mode per placement.
 */
enum AdType: string
{
    case CustomHtml  = 'custom_html';
    case ImageBanner = 'image_banner';
}
