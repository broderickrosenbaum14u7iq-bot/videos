<?php
/**
 * Tube-seo's theme-facing template tags (ARCHITECTURE.md §5).
 *
 * One global function — the same reasoning tube-player's/tube-search's/
 * tube-core's `includes/template-tags.php` already document: this is
 * the entire part of tube-seo's public surface a theme (Phase 8) calls.
 *
 * No `ABSPATH` guard here — `tube-seo.php` already exits before
 * `require_once`-ing this file.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

use Tube_Seo\Plugin as Tube_Seo_Plugin;

/**
 * Render every SEO tag for the current request — `<title>`, meta
 * description, canonical, robots, OpenGraph, Twitter Card, and JSON-LD.
 * Called once, inside `<head>`, by every theme template.
 */
function tube_seo_head(): void
{
    Tube_Seo_Plugin::instance()->head()->render();
}
