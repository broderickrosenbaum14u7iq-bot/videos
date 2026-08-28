<?php
/**
 * Tube Ads' theme-facing template tags.
 *
 * No `ABSPATH` guard here — `tube-ads.php` already exits before
 * `require_once`-ing this file, so a second check here would be dead
 * code, the same posture `tube-player`'s own `template-tags.php` documents.
 *
 * @package Tube_Ads
 */

declare(strict_types=1);

use Tube_Ads\Placement\Placement;
use Tube_Ads\Plugin as Tube_Ads_Plugin;

/**
 * Render one ad placement — the one call a theme needs, with zero
 * provider/type/device-targeting logic of its own to maintain. Echoes
 * nothing at all when ads are globally disabled, this specific
 * placement is disabled/unconfigured, or (for a direct-sold banner)
 * outside its scheduled date window.
 *
 * This project's own theme does not call this directly for every
 * placement (see `Tube_Ads\Plugin::maybe_output_slots()`'s own docblock
 * for why — the short version: several approved template files are a
 * protected baseline this phase must not edit), but it is the real,
 * intended integration point for a theme that can, e.g. a future
 * cloned site: `tube_ads_render('player_above');` anywhere in a
 * template is the entire integration.
 *
 * @param string $placement_id One of `Placement`'s string values, e.g. `'player_above'`.
 */
function tube_ads_render(string $placement_id): void
{
    $placement = Placement::tryFrom($placement_id);

    if (null === $placement) {
        return;
    }

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PlacementRenderer::render() already escapes every part of its own output except the admin-configured, already-sanitized custom-HTML/JS field (see its own docblock).
    echo Tube_Ads_Plugin::instance()->placement_renderer()->render($placement);
}
