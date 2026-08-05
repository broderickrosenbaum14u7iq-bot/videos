<?php
/**
 * Custom rewrite/template_include routing for a slug-addressed archive page.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Content\Routing;

use Closure;
use WP_Query;

/**
 * Custom rewrite/`template_include` routing for one slug-addressed
 * archive page (`/actor/{slug}/`, `/studio/{slug}/`), per
 * ARCHITECTURE.md §15.1 — actor/studio are dedicated tables, not
 * taxonomies (§14), so unlike category/tag they get no native WordPress
 * taxonomy rewrite and need this instead.
 *
 * One concrete, reusable class rather than a near-duplicate
 * `ActorArchiveRouting`/`StudioArchiveRouting` pair: the routing
 * mechanics (rewrite rule, query var, 404 handling, template resolution)
 * are identical for both, differing only in the slug/query-var/template
 * names and how a slug resolves to a domain object — supplied as a
 * closure rather than a shared repository interface, since
 * `ActorRepositoryInterface::find_by_slug()`/`StudioRepositoryInterface::
 * find_by_slug()` return different, unrelated DTOs with no common
 * denominator worth a new interface for. The same "one class, not two
 * near-identical ones" reasoning `Tube_Search\Discovery\PopularVideosQuery`
 * already established in Phase 7.
 *
 * The resolved object is exposed via `set_query_var()` (readable as
 * `get_query_var( "{$query_var}_object" )`), WordPress's own idiomatic
 * mechanism for "a value resolved during routing, needed by the
 * template" — not a `Tube_Core\Plugin` accessor, so two instances of this
 * class (one per archive type) don't add two more entries to Plugin's
 * already-tracked accessor count (ARCHITECTURE.md §19.2); `Plugin::boot()`
 * constructs and wires each instance directly, without caching it.
 *
 * Pagination reuses WordPress core's own `paged` query var (via the
 * rewrite rule's second capture group) rather than a custom one — this
 * route is deliberately not backed by `WP_Query` (the archive's video
 * listing comes from `tube-search`'s index instead, per this phase's
 * "avoid unnecessary WP_Query" instruction), but `paged` is still the
 * conventional place a template looks for "which page am I on."
 */
final class TermArchiveRouting
{
    /**
     * Construct one archive route.
     *
     * @param string  $slug      The rewrite slug, e.g. `'actor'` for `/actor/{term-slug}/`.
     * @param string  $query_var The query var the term's own slug is captured into.
     * @param string  $template  The theme template file this route resolves to, via `locate_template()`.
     * @param Closure $finder    Resolves a term slug to its domain object, or null if unknown.
     *
     * @phpstan-param Closure(string): (object|null) $finder
     */
    public function __construct(
        private readonly string $slug,
        private readonly string $query_var,
        private readonly string $template,
        private readonly Closure $finder
    ) {
    }

    /**
     * Register this route's rewrite rules. Called on `init`, and
     * synchronously during plugin activation (before the rewrite-rules
     * flush) — the same pattern `VideoPostType`/`CategoryTaxonomy`/
     * `TagTaxonomy` already use.
     */
    public function add_rewrite_rules(): void
    {
        add_rewrite_rule(
            '^' . $this->slug . '/([^/]+)/page/([0-9]+)/?$',
            'index.php?' . $this->query_var . '=$matches[1]&paged=$matches[2]',
            'top'
        );

        add_rewrite_rule(
            '^' . $this->slug . '/([^/]+)/?$',
            'index.php?' . $this->query_var . '=$matches[1]',
            'top'
        );
    }

    /**
     * `query_vars` filter callback: makes this route's query var readable via `get_query_var()`.
     *
     * @param string[] $vars The current public query vars.
     *
     * @return string[]
     */
    public function register_query_var(array $vars): array
    {
        $vars[] = $this->query_var;

        return $vars;
    }

    /**
     * `template_include` filter callback: resolves the request's slug,
     * 404s if unknown, otherwise exposes the resolved object via
     * `set_query_var( "{$query_var}_object", $resolved )` and hands off
     * to the theme's own template file.
     *
     * @param string $template The template WordPress would otherwise use.
     */
    public function route_template(string $template): string
    {
        $slug = get_query_var($this->query_var);

        if (! is_string($slug) || '' === $slug) {
            return $template;
        }

        $resolved = ($this->finder)($slug);

        if (null === $resolved) {
            global $wp_query;
            /** @var WP_Query $wp_query */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

            $wp_query->set_404();
            status_header(404);
            nocache_headers();

            return get_404_template();
        }

        set_query_var($this->query_var . '_object', $resolved);

        $located = locate_template($this->template);

        return '' === $located ? $template : $located;
    }
}
