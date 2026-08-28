<?php
/**
 * Builds a PageMeta for each of this project's page types.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Meta;

/**
 * Builds a `PageMeta` for each of this project's page types — one named
 * factory method per type rather than a generic `Factory::create($type)`
 * indirection (this phase's "no Factory pattern" instruction is about
 * that kind of indirection, not a handful of descriptively-named static
 * constructors, an ordinary PHP idiom). Pure text/decision logic given
 * already-resolved scalar inputs — no WordPress calls, fully unit-tested.
 */
final class PageMetaBuilder
{
    /**
     * Meta for a video single page (`/watch/{slug}/`).
     *
     * @param string      $site_name   The site's name.
     * @param string      $video_title The video's title.
     * @param string      $description The video's description.
     * @param string      $canonical   The video's canonical URL — always the bare `/watch/{slug}/` (§15.2).
     * @param string|null $image_url   The video's thumbnail URL, if known.
     */
    public static function for_video(
        string $site_name,
        string $video_title,
        string $description,
        string $canonical,
        ?string $image_url
    ): PageMeta {
        return new PageMeta(
            "{$video_title} | {$site_name}",
            $description,
            $canonical,
            'index, follow',
            'video.other',
            $image_url
        );
    }

    /**
     * Meta for a category/tag/actor/studio archive page. Self-canonical
     * per page number (§15.2 — page 2 is never collapsed to page 1);
     * `noindex` when the current page has zero items (an empty listing
     * carries no indexable content) or when the caller flags the archive
     * as thin regardless of this page's own item count (2026-08-26 SEO
     * audit P1 finding: a free-text tag with only 1-2 videos site-wide is
     * thin/near-duplicate content even though this page isn't literally
     * empty — see `Tube_Seo\Head\SeoHead::resolve_term_archive()` for how
     * that's decided; only tags use this, categories/actors/studios are
     * always curated/deliberate groupings, not thin by construction).
     *
     * @param string $site_name     The site's name.
     * @param string $archive_label The archive kind, e.g. `'Videos'`, `'Actor'`, `'Studio'`.
     * @param string $term_name     The category/tag/actor/studio's display name.
     * @param string $description  The archive's description, if any.
     * @param string $canonical    This page's own (self-canonical) URL.
     * @param int    $page         The current page number (1-indexed).
     * @param int    $item_count   How many items are on this page.
     * @param bool   $force_noindex Whether to noindex regardless of $item_count (thin-tag rule).
     */
    public static function for_archive(
        string $site_name,
        string $archive_label,
        string $term_name,
        string $description,
        string $canonical,
        int $page,
        int $item_count,
        bool $force_noindex = false
    ): PageMeta {
        $title = 1 === $page
            ? "{$term_name} {$archive_label} | {$site_name}"
            : "{$term_name} {$archive_label} \u{2014} Page {$page} | {$site_name}";

        return new PageMeta(
            $title,
            $description,
            $canonical,
            0 === $item_count || $force_noindex ? 'noindex, follow' : 'index, follow',
            'website',
            null
        );
    }

    /**
     * Meta for a search results page (`/search/{query}/`) — always
     * `noindex`, per the standard practice of not indexing internal
     * site-search results (thin/duplicate content, unbounded query space).
     *
     * @param string $site_name  The site's name.
     * @param string $query      The raw search query text.
     * @param string $canonical  This page's own (self-canonical) URL.
     * @param int    $item_count How many results are on this page.
     */
    public static function for_search(string $site_name, string $query, string $canonical, int $item_count): PageMeta
    {
        $title = '' === $query
            ? "Search | {$site_name}"
            : "Search results for \"{$query}\" | {$site_name}";

        return new PageMeta(
            $title,
            $item_count . ' result(s) for "' . $query . '".',
            $canonical,
            'noindex, follow',
            'website',
            null
        );
    }

    /**
     * Meta for a low-value WordPress-core page type this project never
     * deliberately built content for — attachment pages, author
     * archives, date archives (2026-08-26 SEO audit P1 finding). Always
     * `noindex, follow`: none of these carry curated, indexable content
     * of their own, but their outbound links (to the real video/archive
     * pages they list or attach to) are still worth crawling.
     *
     * @param string $title     The `<title>` text.
     * @param string $canonical This page's own (self-canonical) URL.
     */
    public static function for_low_value_archive(string $title, string $canonical): PageMeta
    {
        return new PageMeta($title, '', $canonical, 'noindex, follow', 'website', null);
    }

    /**
     * Meta for the homepage.
     *
     * @param string $site_name   The site's name.
     * @param string $description The site's tagline/description.
     * @param string $canonical   The homepage's canonical URL.
     */
    public static function for_home(string $site_name, string $description, string $canonical): PageMeta
    {
        return new PageMeta($site_name, $description, $canonical, 'index, follow', 'website', null);
    }
}
