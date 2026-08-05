<?php
/**
 * Small template-local helpers shared across this theme's page templates.
 *
 * No `ABSPATH` guard here — `functions.php` already exits before
 * `require_once`-ing this file (the same reasoning every plugin's own
 * `includes/template-tags.php` documents).
 *
 * @package Tube_Theme
 */

declare(strict_types=1);

/**
 * The current page number, from WordPress's own `paged` query var —
 * shared by every listing template so each one doesn't repeat the same
 * `is_numeric()` narrowing of `get_query_var()`'s untyped return.
 */
function tube_theme_current_page(): int
{
    $paged = get_query_var('paged');

    return is_numeric($paged) && (int) $paged > 0 ? (int) $paged : 1;
}
