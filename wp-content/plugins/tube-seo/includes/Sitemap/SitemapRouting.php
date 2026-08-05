<?php
/**
 * Serves generated sitemap XML files at clean URLs.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Sitemap;

/**
 * Serves the sitemap files `SitemapGenerator` writes to disk at clean,
 * top-level URLs (`/video-sitemap.xml`, `/video-sitemap-index.xml`,
 * `/video-sitemap-1.xml`, ...) — a raw file read via `readfile()`, not a
 * WordPress template or `WP_Query`, matching this phase's "no WP_Query"
 * instruction and the fact that these are already-rendered static files.
 *
 * Same rewrite-rule/query-var registration shape as
 * `Tube_Core\Content\Routing\TermArchiveRouting`/`Tube_Search\Search\
 * SearchRouting`, but hooks `template_redirect` (an action, not the
 * `template_include` filter) since there is no template file to hand
 * back for the success case — the response is the raw file content
 * followed by `exit`, not a template path.
 */
final class SitemapRouting
{
    /**
     * The query var the rewrite rule stores the requested filename under.
     */
    private const QUERY_VAR = 'tube_seo_sitemap_file';

    /**
     * The only filenames this route will ever serve — re-validated here
     * even though the rewrite rule below already constrains it, because
     * WordPress's public query vars (`query_vars` filter) are also
     * settable directly via `?tube_seo_sitemap_file=...`, not just
     * through the rewrite match; this is the real boundary against a
     * path-traversal attempt reaching `SitemapGenerator::directory()`.
     */
    private const FILENAME_PATTERN = '/^video-sitemap(?:-index|-[0-9]+)?\.xml$/';

    /**
     * Register the rewrite rule matching sitemap filenames at the site root.
     */
    public function add_rewrite_rules(): void
    {
        add_rewrite_rule(
            '^(video-sitemap(?:-index|-[0-9]+)?\.xml)$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]',
            'top'
        );
    }

    /**
     * Register this route's query var with WordPress.
     *
     * @param string[] $vars The current public query vars.
     *
     * @return string[]
     */
    public function register_query_var(array $vars): array
    {
        $vars[] = self::QUERY_VAR;

        return $vars;
    }

    /**
     * If the current request matches a sitemap filename, serve it
     * directly and terminate the request; otherwise, do nothing (leave
     * WordPress's normal template flow untouched).
     */
    public function maybe_serve(): void
    {
        $filename = get_query_var(self::QUERY_VAR);

        if (! is_string($filename) || '' === $filename) {
            return;
        }

        if (1 !== preg_match(self::FILENAME_PATTERN, $filename)) {
            return;
        }

        $path = trailingslashit(SitemapGenerator::directory()) . $filename;

        if (! file_exists($path)) {
            global $wp_query;
            /** @var \WP_Query $wp_query */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

            $wp_query->set_404();
            status_header(404);
            nocache_headers();

            return;
        }

        header('Content-Type: application/xml; charset=UTF-8');

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- serving this plugin's own generated static file directly; no FTP credential scenario applies (that's what WP_Filesystem exists for).
        readfile($path);

        exit;
    }
}
