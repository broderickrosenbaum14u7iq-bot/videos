<?php
/**
 * Renders every SEO tag for the current request.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Head;

use Tube_Core\Content\Actor;
use Tube_Core\Content\Studio;
use Tube_Core\Plugin as Tube_Core_Plugin;
use Tube_Player\Plugin as Tube_Player_Plugin;
use Tube_Search\Discovery\ArchivePage;
use Tube_Search\Plugin as Tube_Search_Plugin;
use Tube_Seo\JsonLd\BreadcrumbListBuilder;
use Tube_Seo\JsonLd\CollectionPageBuilder;
use Tube_Seo\JsonLd\VideoObjectBuilder;
use Tube_Seo\Meta\PageMeta;
use Tube_Seo\Meta\PageMetaBuilder;
use WP_Term;

/**
 * Renders every SEO tag for the current request: `<title>`, meta
 * description, canonical, robots, OpenGraph, Twitter Card, and JSON-LD
 * (`VideoObject`/`BreadcrumbList` on a video page, `CollectionPage`/
 * `BreadcrumbList` on an archive/search page) — the one class
 * `tube_seo_head()` delegates to.
 *
 * Detects the current page type via WordPress's own conditional tags
 * and tube-core's/tube-search's query-var-backed "current object"
 * template tags (`tube_core_get_current_actor()`, etc.) rather than a
 * Strategy-pattern class per page type — a straightforward `match`/
 * `if`-cascade is the simplest correct shape for "which of six known
 * page types is this," per this phase's explicit "no Strategy pattern
 * unless required" instruction.
 *
 * WordPress/tube-core/tube-player/tube-search-coupled throughout —
 * verified via integration tests and live checks, not unit-tested, the
 * same split every thin real-data orchestrator in this project uses
 * (`Tube_Search\Index\VideoIndexer` is the closest precedent). The pure
 * value/structure computation it delegates to (`PageMetaBuilder`,
 * `VideoObjectBuilder`, `BreadcrumbListBuilder`, `CollectionPageBuilder`)
 * is unit-tested instead.
 */
final class SeoHead
{
    /**
     * Render every SEO tag for the current request.
     */
    public function render(): void
    {
        [$meta, $json_ld] = $this->resolve();

        echo '<title>' . esc_html($meta->title) . "</title>\n";
        echo '<meta name="description" content="' . esc_attr($meta->description) . "\">\n";
        echo '<link rel="canonical" href="' . esc_url($meta->canonical) . "\">\n";
        echo '<meta name="robots" content="' . esc_attr($meta->robots) . "\">\n";

        echo '<meta property="og:type" content="' . esc_attr($meta->og_type) . "\">\n";
        echo '<meta property="og:title" content="' . esc_attr($meta->title) . "\">\n";
        echo '<meta property="og:description" content="' . esc_attr($meta->description) . "\">\n";
        echo '<meta property="og:url" content="' . esc_url($meta->canonical) . "\">\n";

        $twitter_card = null === $meta->image_url ? 'summary' : 'summary_large_image';
        echo '<meta name="twitter:card" content="' . esc_attr($twitter_card) . "\">\n";
        echo '<meta name="twitter:title" content="' . esc_attr($meta->title) . "\">\n";
        echo '<meta name="twitter:description" content="' . esc_attr($meta->description) . "\">\n";

        if (null !== $meta->image_url) {
            echo '<meta property="og:image" content="' . esc_url($meta->image_url) . "\">\n";
            echo '<meta name="twitter:image" content="' . esc_url($meta->image_url) . "\">\n";
        }

        foreach ($json_ld as $structure) {
            $encoded = wp_json_encode($structure);

            if (false !== $encoded) {
                echo '<script type="application/ld+json">' . wp_json_encode($structure) . "</script>\n";
            }
        }
    }

    /**
     * Resolve the current page's meta values and JSON-LD structures.
     *
     * @return array{0: PageMeta, 1: list<array<string, mixed>>}
     */
    private function resolve(): array
    {
        $site_name = get_bloginfo('name');

        if (is_singular('video')) {
            return $this->resolve_video($site_name);
        }

        $actor = tube_core_get_current_actor();

        if ($actor instanceof Actor) {
            return $this->resolve_archive(
                $site_name,
                'Actor',
                $actor->name,
                $actor->bio ?? '',
                'Actor',
                $actor->id
            );
        }

        $studio = tube_core_get_current_studio();

        if ($studio instanceof Studio) {
            return $this->resolve_archive(
                $site_name,
                'Studio',
                $studio->name,
                $studio->description ?? '',
                'Studio',
                $studio->id
            );
        }

        if (is_tax('video_category')) {
            return $this->resolve_term_archive($site_name, 'Videos', 'Category');
        }

        if (is_tax('video_tag')) {
            return $this->resolve_term_archive($site_name, 'Videos', 'Tag');
        }

        $search_query = tube_search_current_query();

        if ('' !== $search_query) {
            return $this->resolve_search($site_name, $search_query);
        }

        if (is_front_page()) {
            return [
                PageMetaBuilder::for_home($site_name, get_bloginfo('description'), home_url('/')),
                [],
            ];
        }

        $title = get_the_title();
        $title = '' === $title ? $site_name : $title;

        return [
            PageMetaBuilder::for_home($title, get_bloginfo('description'), self::current_url()),
            [],
        ];
    }

    /**
     * Resolve meta/JSON-LD for a video single page.
     *
     * @param string $site_name The site's name.
     *
     * @return array{0: PageMeta, 1: list<array<string, mixed>>}
     */
    private function resolve_video(string $site_name): array
    {
        $video_id  = get_queried_object_id();
        $title     = get_the_title($video_id);
        $canonical = get_permalink($video_id);
        $canonical = false === $canonical ? home_url('/') : $canonical;

        $description = get_the_excerpt($video_id);
        $description = '' === $description ? $title : $description;

        $metadata    = Tube_Core_Plugin::instance()->video_metadata_repository()->find($video_id);
        $indexed     = Tube_Search_Plugin::instance()->search_index_repository()->find($video_id);
        $views_total = null === $indexed ? 0 : $indexed->views_total;

        $image_url = null;
        $embed_url = '';

        if (null !== $metadata) {
            $provider  = Tube_Player_Plugin::instance()->video_provider();
            $image_url = $provider->thumbnail_url(
                $metadata->cf_stream_uid,
                $metadata->thumbnail_time_seconds,
                1200,
                630
            );
            $embed_url = $provider->embed_url($metadata->cf_stream_uid);
        }

        $meta = PageMetaBuilder::for_video($site_name, $title, $description, $canonical, $image_url);

        $duration = null === $metadata?->duration_seconds
            ? null
            : VideoObjectBuilder::iso8601_duration($metadata->duration_seconds);

        $upload_date = get_the_date(DATE_ATOM, $video_id);
        $upload_date = is_string($upload_date) ? $upload_date : '';

        $video_object = VideoObjectBuilder::build(
            $title,
            $description,
            $image_url ?? '',
            $upload_date,
            $duration,
            $embed_url,
            $views_total
        );

        $breadcrumb = BreadcrumbListBuilder::build(self::video_breadcrumb_items($video_id, $title));

        return [$meta, [$video_object, $breadcrumb]];
    }

    /**
     * Resolve meta/JSON-LD for a `video_category`/`video_tag` taxonomy archive page.
     *
     * @param string $site_name     The site's name.
     * @param string $archive_label The archive kind label, e.g. `'Videos'`.
     * @param string $column_label  Which candidate column this archive matches — `'Category'`/`'Tag'`.
     *
     * @return array{0: PageMeta, 1: list<array<string, mixed>>}
     */
    private function resolve_term_archive(string $site_name, string $archive_label, string $column_label): array
    {
        $term = get_queried_object();

        if (! $term instanceof WP_Term) {
            return [PageMetaBuilder::for_home($site_name, '', self::current_url()), []];
        }

        $page = self::current_page();

        $archive_page = 'Category' === $column_label
            ? tube_search_by_category($term->term_id, $page)
            : tube_search_by_tag($term->term_id, $page);

        $description = term_description($term->term_id);
        $description = '' === $description ? $term->name : wp_strip_all_tags($description);

        return $this->build_archive_result(
            $site_name,
            $archive_label,
            $term->name,
            $description,
            self::term_archive_url($term, $page),
            $page,
            $archive_page
        );
    }

    /**
     * Resolve meta/JSON-LD for an actor/studio archive page.
     *
     * @param string $site_name     The site's name.
     * @param string $archive_label The archive kind label — `'Actor'`/`'Studio'`.
     * @param string $name          The actor/studio's display name.
     * @param string $description   The actor/studio's bio/description.
     * @param string $column_label  Which candidate column this archive matches — `'Actor'`/`'Studio'`.
     * @param int    $id            The actor/studio's row ID.
     *
     * @return array{0: PageMeta, 1: list<array<string, mixed>>}
     */
    private function resolve_archive(
        string $site_name,
        string $archive_label,
        string $name,
        string $description,
        string $column_label,
        int $id
    ): array {
        $page = self::current_page();

        $archive_page = 'Actor' === $column_label
            ? tube_search_by_actor($id, $page)
            : tube_search_by_studio($id, $page);

        return $this->build_archive_result(
            $site_name,
            $archive_label,
            $name,
            $description,
            self::current_url(),
            $page,
            $archive_page
        );
    }

    /**
     * Shared meta/JSON-LD assembly for every archive page type.
     *
     * @param string      $site_name     The site's name.
     * @param string      $archive_label The archive kind label.
     * @param string      $name          The archived term/actor/studio's display name.
     * @param string      $description   The archive's description.
     * @param string      $canonical     This page's own (self-canonical) URL.
     * @param int         $page          The current page number.
     * @param ArchivePage $archive_page  The fetched listing page.
     *
     * @return array{0: PageMeta, 1: list<array<string, mixed>>}
     */
    private function build_archive_result(
        string $site_name,
        string $archive_label,
        string $name,
        string $description,
        string $canonical,
        int $page,
        ArchivePage $archive_page
    ): array {
        $meta = PageMetaBuilder::for_archive(
            $site_name,
            $archive_label,
            $name,
            $description,
            $canonical,
            $page,
            count($archive_page->items)
        );

        $collection_page = CollectionPageBuilder::build($name, $canonical, $archive_page->total);
        $breadcrumb      = BreadcrumbListBuilder::build(
            [
                [
                    'name' => 'Home',
                    'url'  => home_url('/'),
                ],
                [
                    'name' => $name,
                    'url'  => $canonical,
                ],
            ]
        );

        return [$meta, [$collection_page, $breadcrumb]];
    }

    /**
     * Resolve meta/JSON-LD for a search results page.
     *
     * @param string $site_name The site's name.
     * @param string $query     The raw search query text.
     *
     * @return array{0: PageMeta, 1: list<array<string, mixed>>}
     */
    private function resolve_search(string $site_name, string $query): array
    {
        $page   = self::current_page();
        $result = tube_search_query(
            [
                'q'    => $query,
                'page' => $page,
            ]
        );

        $meta = PageMetaBuilder::for_search($site_name, $query, self::current_url(), count($result));

        $collection_page = CollectionPageBuilder::build(
            "Search: {$query}",
            self::current_url(),
            count($result)
        );

        return [$meta, [$collection_page]];
    }

    /**
     * Build the breadcrumb items for a video page: Home, its first
     * category (if any), then the video itself.
     *
     * @param int    $video_id The video post ID.
     * @param string $title    The video's title.
     *
     * @return list<array{name: string, url: string}>
     */
    private static function video_breadcrumb_items(int $video_id, string $title): array
    {
        $items = [
            [
                'name' => 'Home',
                'url'  => home_url('/'),
            ],
        ];

        $terms = get_the_terms($video_id, 'video_category');

        if (is_array($terms) && [] !== $terms) {
            $term = $terms[0];
            $link = get_term_link($term);

            $items[] = [
                'name' => $term->name,
                'url'  => is_string($link) ? $link : home_url('/'),
            ];
        }

        $permalink = get_permalink($video_id);
        $items[]   = [
            'name' => $title,
            'url'  => false === $permalink ? home_url('/') : $permalink,
        ];

        return $items;
    }

    /**
     * This page's own self-canonical URL, per §15.2 — the current
     * request's clean path (no tracking/query params), on the site's
     * single production origin.
     */
    private static function current_url(): string
    {
        global $wp;
        /** @var \WP $wp */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        return home_url(add_query_arg([], $wp->request));
    }

    /**
     * The archive term's own self-canonical URL for the given page number, per §15.2.
     *
     * @param WP_Term $term The category/tag term.
     * @param int     $page The current page number.
     */
    private static function term_archive_url(WP_Term $term, int $page): string
    {
        $base = get_term_link($term);
        $base = $base instanceof \WP_Error ? home_url('/') : $base;

        return $page > 1 ? trailingslashit($base) . 'page/' . $page . '/' : $base;
    }

    /**
     * The current page number, from WordPress's own `paged` query var.
     */
    private static function current_page(): int
    {
        $paged = get_query_var('paged');

        return is_numeric($paged) && (int) $paged > 0 ? (int) $paged : 1;
    }
}
