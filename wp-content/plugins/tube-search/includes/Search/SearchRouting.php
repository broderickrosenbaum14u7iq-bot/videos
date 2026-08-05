<?php
/**
 * Custom rewrite/template_include routing for /search/{query}/.
 *
 * @package Tube_Search
 */

declare(strict_types=1);

namespace Tube_Search\Search;

/**
 * Custom rewrite/`template_include` routing for `/search/{query}/`, per
 * ARCHITECTURE.md §15.1 — a custom rewrite into a `tube_search_q` query
 * var, deliberately not WordPress core's native `?s=` search (which
 * would run a real `WP_Query` search against `wp_posts`; this project's
 * search always goes through `wp_tube_search_index` instead, per §2.6).
 *
 * Unlike `Tube_Core\Content\Routing\TermArchiveRouting`, there is no
 * "unknown slug" 404 case here — any query text, including one with zero
 * matches, is a valid search results page — so this is a small, separate
 * class rather than a forced reuse of that one's finder/404 shape.
 */
final class SearchRouting
{
    /**
     * Register this route's rewrite rules. Called on `init`, and
     * synchronously during plugin activation (before the rewrite-rules
     * flush) — the same pattern `Tube_Core\Content\Routing\
     * TermArchiveRouting::add_rewrite_rules()` uses.
     */
    public function add_rewrite_rules(): void
    {
        add_rewrite_rule(
            '^search/([^/]+)/page/([0-9]+)/?$',
            'index.php?tube_search_q=$matches[1]&paged=$matches[2]',
            'top'
        );

        add_rewrite_rule('^search/([^/]+)/?$', 'index.php?tube_search_q=$matches[1]', 'top');
    }

    /**
     * `query_vars` filter callback: makes `tube_search_q` readable via `get_query_var()`.
     *
     * @param string[] $vars The current public query vars.
     *
     * @return string[]
     */
    public function register_query_var(array $vars): array
    {
        $vars[] = 'tube_search_q';

        return $vars;
    }

    /**
     * `template_include` filter callback: hands off to the theme's `search.php` for any `/search/{query}/` request.
     *
     * @param string $template The template WordPress would otherwise use.
     */
    public function route_template(string $template): string
    {
        $query = get_query_var('tube_search_q');

        if (! is_string($query) || '' === $query) {
            return $template;
        }

        $located = locate_template('search.php');

        return '' === $located ? $template : $located;
    }
}
