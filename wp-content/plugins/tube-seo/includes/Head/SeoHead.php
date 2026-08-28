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
use Tube_Player\Video\ImageSize;
use Tube_Search\Discovery\ArchivePage;
use Tube_Search\Plugin as Tube_Search_Plugin;
use Tube_Seo\JsonLd\BreadcrumbListBuilder;
use Tube_Seo\JsonLd\CollectionPageBuilder;
use Tube_Seo\JsonLd\VideoObjectBuilder;
use Tube_Seo\JsonLd\WebSiteBuilder;
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

        self::render_verification_tags();

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
     * The admin-configured Homepage SEO Title
     * (`Tube_Seo\Admin\HomepageSeoSettings::OPTION_TITLE`), or `''` if
     * unset — see self::resolve()'s `is_front_page()` branch for how
     * this is used.
     */
    private static function homepage_custom_title(): string
    {
        $value = get_option('tube_seo_home_title', '');

        return is_string($value) ? trim($value) : '';
    }

    /**
     * The admin-configured Homepage Meta Description
     * (`Tube_Seo\Admin\HomepageSeoSettings::OPTION_DESCRIPTION`), or
     * `''` if unset — see self::resolve()'s `is_front_page()` branch for
     * how this is used.
     */
    private static function homepage_custom_description(): string
    {
        $value = get_option('tube_seo_home_description', '');

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Echo Google/Bing webmaster site-verification meta tags, site-wide,
     * if and only if a real token has been configured — output nothing
     * at all otherwise (2026-08-26 SEO audit P2: this plugin has no
     * admin settings UI of any kind to surface these through yet, so
     * they're plain `wp_options`, set via `wp option update` or
     * `update_option()` until/unless a settings page is ever built; see
     * `tube_seo_sitemap_urls_per_sitemap`/`tube_seo_thin_tag_threshold`
     * for the same "filter/option now, UI later if ever needed" pattern
     * already used elsewhere in this plugin). No token is ever invented
     * or defaulted here.
     */
    private static function render_verification_tags(): void
    {
        $google = get_option('tube_seo_google_site_verification', '');

        if (is_string($google) && '' !== $google) {
            echo '<meta name="google-site-verification" content="' . esc_attr($google) . "\">\n";
        }

        $bing = get_option('tube_seo_bing_site_verification', '');

        if (is_string($bing) && '' !== $bing) {
            echo '<meta name="msvalidate.01" content="' . esc_attr($bing) . "\">\n";
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

        if ('' !== tube_search_current_query()) {
            return $this->resolve_search($site_name);
        }

        // Attachment pages, author archives, and date archives are WordPress
        // core page types this project never deliberately built content for
        // — noindexed rather than falling through to the generic branch
        // below, which would otherwise index them as if they were curated
        // pages (2026-08-26 SEO audit P1 finding).
        if (is_attachment() || is_author() || is_date()) {
            $title = get_the_title();
            $title = '' === $title ? $site_name : "{$title} | {$site_name}";

            return [PageMetaBuilder::for_low_value_archive($title, self::current_url()), []];
        }

        // The member system's frontend account page (`/tai-khoan/`,
        // Tube_Members\Routing\AccountRouting) is a `template_include`-
        // routed virtual page with no WP_Query post/page/archive of its
        // own, so none of the branches above ever match it and it would
        // otherwise fall through to the generic branch below as if it
        // were curated, indexable content. It carries no content of its
        // own to index (an account holder's private profile) but does
        // link back to real videos — same `noindex, follow` shape
        // `for_low_value_archive()` above already exists for, per Phase
        // 32 of the member/comment system build (2026-08-26): "Frontend
        // account/profile pages should generally: NOINDEX, FOLLOW."
        // Read via a bare query var, not a `Tube_Members` class
        // reference, so tube-seo has no compile-time dependency on
        // tube-members and this check is simply inert (get_query_var()
        // returns '') if that plugin is ever inactive.
        $tube_members_account_var = get_query_var('tube_members_account');

        if (is_string($tube_members_account_var) && '1' === $tube_members_account_var) {
            return [PageMetaBuilder::for_low_value_archive($site_name, self::current_url()), []];
        }

        // Same reasoning, same bare-query-var/no-compile-time-dependency
        // shape, for the email-verification landing page
        // (`/xac-thuc-email/`, 2026-08-27 email-verification task,
        // Phase 33) -- a one-time utility page a visitor only ever
        // reaches via a link with a personal token in it, never a page
        // worth indexing.
        $tube_members_verify_email_var = get_query_var('tube_members_verify_email');

        if (is_string($tube_members_verify_email_var) && '1' === $tube_members_verify_email_var) {
            return [PageMetaBuilder::for_low_value_archive($site_name, self::current_url()), []];
        }

        if (is_front_page()) {
            // 2026-08-26 SEO audit P2: WebSiteBuilder only — no Organization
            // (this site has no real logo/social-profile/legal-entity data
            // configured anywhere; `wp_options` has no site_icon, no custom
            // logo, no social-profile options, confirmed by direct query, and
            // fabricating any of those was explicitly ruled out), and no
            // `potentialAction`/SearchAction (Google deprecated the Sitelinks
            // Search Box feature it powered in November 2024 — see
            // developers.google.com/search/blog/2024/10/sitelinks-search-box
            // — and this project's real search URL is a path segment,
            // `/search/{query}/`, not the `?s=` query-string shape most
            // SearchAction examples assume).
            //
            // 2026-08-26 Homepage SEO controls: $home_title/$home_description
            // (Admin\HomepageSeoSettings, "Tube SEO -> Homepage SEO") take
            // priority over the site name/tagline when set, becoming the
            // <title>/og:title/twitter:title and meta description/
            // og:description/twitter:description alike — PageMeta is the
            // single source SeoHead::render() reads every one of those from,
            // so setting it here is the only change needed for all of them
            // to move together, with no separate/duplicate output path.
            // $site_name itself is untouched and still goes to WebSiteBuilder
            // below: the SEO title is a distinct concept from the site's own
            // entity name (see HomepageSeoSettings' own docblock).
            $home_title       = self::homepage_custom_title();
            $home_description = self::homepage_custom_description();

            $title       = '' !== $home_title ? $home_title : $site_name;
            $description = '' !== $home_description ? $home_description : get_bloginfo('description');

            return [
                PageMetaBuilder::for_home($title, $description, home_url('/')),
                [WebSiteBuilder::build($site_name, home_url('/'))],
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
            $provider = Tube_Player_Plugin::instance()->video_provider();
            // resolve_urls() resolves metadata->og_image_id (a WordPress
            // attachment ID, ADR-0001), falling back to
            // metadata->poster_image_id when no OG-image override has
            // been set — see SitemapGenerator::build_entry()'s docblock
            // for why (2026-08-26 SEO audit finding, P0). Still no
            // Cloudflare Stream thumbnail fallback of any kind. Final
            // fallback is WordPress's own native Featured Image
            // (`_thumbnail_id`) — a real, legitimately-uploaded Media
            // Library attachment some videos already have set even
            // though neither tube-core image field was ever populated
            // for them (2026-08-26 SEO audit P2 finding, investigated
            // per-video, not assumed). null here means none of the
            // three fields resolve to a real attachment;
            // og:image/twitter:image/thumbnailUrl are omitted below, not
            // fabricated.
            $image_url = Tube_Player_Plugin::instance()->image_renderer()->resolve_urls(
                $metadata->og_image_id ?? $metadata->poster_image_id ?? self::native_featured_image_id($video_id),
                ImageSize::OgImage
            )['src'];
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
            $image_url,
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

        // Free-text tags routinely land at 1-2 videos site-wide (2026-08-26
        // SEO audit P1 finding) — thin/near-duplicate content, unlike
        // categories, which are a small, deliberately-curated set. Only
        // 'Tag' is gated; self::resolve_archive() (actor/studio) never
        // passes this at all, and stays index-eligible regardless of size.
        $force_noindex = 'Tag' === $column_label && $archive_page->total < self::thin_tag_threshold();

        return $this->build_archive_result(
            $site_name,
            $archive_label,
            $term->name,
            $description,
            self::term_archive_url($term, $page),
            $page,
            $archive_page,
            $force_noindex
        );
    }

    /**
     * The minimum total published-video count a tag needs to stay
     * indexable — below this, every page of that tag's archive is
     * `noindex, follow` (2026-08-26 SEO audit P1 finding). Filterable
     * for the same reason `SitemapGenerator`'s own thresholds are.
     */
    private static function thin_tag_threshold(): int
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the literal, tube_seo_-prefixed filter name is right here as a string, not dynamic.
        $value = apply_filters('tube_seo_thin_tag_threshold', 3);

        return is_int($value) && $value >= 0 ? $value : 3;
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
     * @param bool        $force_noindex Whether to noindex regardless of this page's own item count (thin-tag rule).
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
        ArchivePage $archive_page,
        bool $force_noindex = false
    ): array {
        $meta = PageMetaBuilder::for_archive(
            $site_name,
            $archive_label,
            $name,
            $description,
            $canonical,
            $page,
            count($archive_page->items),
            $force_noindex
        );

        $collection_page = CollectionPageBuilder::build($name, $canonical, $archive_page->total);
        $breadcrumb      = BreadcrumbListBuilder::build(
            [
                [
                    // See self::video_breadcrumb_items()'s docblock comment for why this is
                    // Vietnamese, matching the theme's own visible breadcrumb label.
                    'name' => 'Trang chủ',
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
     * Resolve meta/JSON-LD for a search results page. Resolves the query
     * text itself (`tube_search_current_query_display()`), rather than
     * taking it as a parameter — every use in this method (matching,
     * `<title>`/meta description, JSON-LD name) needs real, decoded UTF-8
     * text, so there is no raw-percent-encoded-text consumer here for a
     * caller to hand in instead.
     *
     * @param string $site_name The site's name.
     *
     * @return array{0: PageMeta, 1: list<array<string, mixed>>}
     */
    private function resolve_search(string $site_name): array
    {
        $query_display = tube_search_current_query_display();
        $page          = self::current_page();
        $result        = tube_search_query(
            [
                'q'    => $query_display,
                'page' => $page,
            ]
        );

        $meta = PageMetaBuilder::for_search($site_name, $query_display, self::current_url(), count($result));

        $collection_page = CollectionPageBuilder::build(
            "Search: {$query_display}",
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
                // Matches the theme's own visible breadcrumb label
                // (template-parts/breadcrumbs.php) — this JSON-LD
                // BreadcrumbList previously hardcoded the English
                // "Home" while the page itself showed "Trang chủ", a
                // real schema/visible-content mismatch (2026-08-26 SEO
                // audit finding, P1).
                'name' => 'Trang chủ',
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

        // user_trailingslashit(), not a bare home_url() concatenation:
        // `$wp->request` (the matched-rewrite-rule request string) never
        // carries a trailing slash, so every URL built from it directly
        // came out missing one — a real, live canonical/OG-url/
        // CollectionPage-url mismatch found on the search page
        // (2026-08-26 SEO audit P2 finding): WordPress's own
        // redirect_canonical() 301s a slash-less /search/{q} request to
        // /search/{q}/, but this method's canonical said /search/{q} —
        // pointing at a URL that isn't the one WordPress itself treats as
        // canonical. user_trailingslashit() matches this site's actual
        // configured permalink convention rather than assuming one.
        return home_url(user_trailingslashit(add_query_arg([], $wp->request)));
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

    /**
     * This video's WordPress native Featured Image attachment ID, if
     * one is set — the final image fallback (see self::resolve_video()'s
     * docblock comment for why).
     *
     * @param int $video_id The video post ID.
     */
    private static function native_featured_image_id(int $video_id): ?int
    {
        $thumbnail_id = get_post_thumbnail_id($video_id);

        return false === $thumbnail_id || 0 === $thumbnail_id ? null : (int) $thumbnail_id;
    }
}
