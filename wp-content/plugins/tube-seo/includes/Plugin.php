<?php
/**
 * Tube SEO's bootstrap.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo;

use Tube_Seo\Admin\HomepageSeoSettings;
use Tube_Seo\CLI\SitemapCommand;
use Tube_Seo\Head\SeoHead;
use Tube_Seo\Sitemap\PublishedVideoRepository;
use Tube_Seo\Sitemap\SitemapGenerator;
use Tube_Seo\Sitemap\SitemapRouting;
use Tube_Seo\Sitemap\SitemapXmlBuilder;
use WP_CLI;

/**
 * Tube SEO's bootstrap — the composition-root shape every other tube-*
 * plugin already uses: lazy accessors for `SeoHead` and
 * `SitemapGenerator`, hook wiring in `boot()`, and `activate()`/
 * `deactivate()` for the sitemap route's rewrite rule.
 */
final class Plugin
{
    /**
     * The shared instance, lazily created by self::instance().
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Lazily created by self::head().
     *
     * @var SeoHead|null
     */
    private ?SeoHead $head = null;

    /**
     * Lazily created by self::sitemap_generator().
     *
     * @var SitemapGenerator|null
     */
    private ?SitemapGenerator $sitemap_generator = null;

    /**
     * Private: use self::instance() instead.
     */
    private function __construct()
    {
    }

    /**
     * The shared Plugin instance.
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Wire up hooks. Called on `plugins_loaded`.
     *
     * `tube_seo_head()` itself stays a plain function call from the theme
     * (not hook-driven — see `head()`'s own docblock), but the sitemap
     * route needs its rewrite rule/query var/`template_redirect` handler
     * registered on every request, the same way `Tube_Core\Content\
     * Routing\TermArchiveRouting`/`Tube_Search\Search\SearchRouting` wire
     * themselves in their own plugins' `boot()`.
     */
    public function boot(): void
    {
        $routing = new SitemapRouting();

        add_action('init', [$routing, 'add_rewrite_rules']);
        add_filter('query_vars', [$routing, 'register_query_var']);
        // Priority 1: WordPress core's own redirect_canonical() runs on
        // template_redirect at the default priority (10) and would
        // 301-redirect a slash-less, non-post-type URL like
        // /video-sitemap.xml to /video-sitemap.xml/ before this class
        // ever got a chance to serve it — confirmed live. Running first
        // and exit()ing on a real match sidesteps that entirely.
        add_action('template_redirect', [$routing, 'maybe_serve'], 1);

        // WordPress core's own rel_canonical() (wp-includes/default-filters.php,
        // registered before plugins_loaded, so it's already on the hook by the
        // time this runs) fires on every is_singular() request and would emit a
        // second <link rel="canonical"> alongside SeoHead's own — SeoHead
        // already renders a real, correct canonical for every page type this
        // site has (video/archive/search/home), so core's is pure duplication
        // everywhere it would ever fire here (2026-08-26 SEO audit finding, P0).
        remove_action('wp_head', 'rel_canonical');

        // WordPress core's native sitemap system auto-discovers the `video`
        // post type (publicly_queryable + show_in_rest) and lists every
        // published video in /wp-sitemap-posts-video-*.xml with only generic
        // <loc>/<lastmod> fields — a second, competing, always-in-sync-by-
        // definition sitemap for the exact same URLs this plugin's own
        // richer video-specific sitemap (<video:thumbnail_loc>, <video:
        // duration>, etc.) already covers. Excluding just `video` here
        // leaves core's sitemap fully intact for any other post type
        // (2026-08-26 SEO audit finding, P0).
        add_filter('wp_sitemaps_post_types', [self::class, 'exclude_video_from_core_sitemap']);

        // robots.txt's own `Sitemap:` directive (WP_Sitemaps::add_robots(),
        // hooked at priority 0) only ever points at core's generic sitemap
        // above — crawlers relying on that directive would never discover
        // this plugin's actual video sitemap. Priority 20 (after core's 0)
        // appends a second line rather than replacing core's, since core's
        // own sitemap is still valid for whatever isn't `video`.
        add_filter('robots_txt', [self::class, 'add_video_sitemap_to_robots_txt'], 20, 2);

        // REST API responses (/wp-json/...) have no HTML <head> for a meta
        // robots tag to live in, and this site's REST routes exist for its
        // own front-end JS, not as public content — nothing else on this
        // site sends an X-Robots-Tag header at all (2026-08-26 SEO audit
        // P1 finding), so this is additive, not a duplicate/conflict risk.
        add_filter('rest_pre_serve_request', [self::class, 'add_rest_x_robots_tag_header']);

        // "Tube SEO -> Homepage SEO" — admin_menu/admin_init only ever run
        // in wp-admin, so this costs nothing on a real front-end request.
        $homepage_seo_settings = new HomepageSeoSettings();

        add_action('admin_menu', [$homepage_seo_settings, 'register_menu']);
        add_action('admin_init', [$homepage_seo_settings, 'register_settings']);

        $this->register_cli_commands();
    }

    /**
     * Register this plugin's rewrite rule and flush it, so the sitemap
     * route works immediately without a manual `wp rewrite flush`.
     */
    public static function activate(): void
    {
        (new SitemapRouting())->add_rewrite_rules();

        flush_rewrite_rules();
    }

    /**
     * Flush rewrite rules on deactivation, so this plugin's route doesn't linger.
     */
    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    /**
     * The SEO head renderer.
     *
     * Public: `includes/template-tags.php`'s `tube_seo_head()` is a thin wrapper around this.
     */
    public function head(): SeoHead
    {
        if (null === $this->head) {
            $this->head = new SeoHead();
        }

        return $this->head;
    }

    /**
     * The video sitemap generator.
     */
    public function sitemap_generator(): SitemapGenerator
    {
        if (null === $this->sitemap_generator) {
            $this->sitemap_generator = new SitemapGenerator(new PublishedVideoRepository(), new SitemapXmlBuilder());
        }

        return $this->sitemap_generator;
    }

    /**
     * Remove `video` from WordPress core's native sitemap post-type list —
     * see self::boot()'s docblock comment for why.
     *
     * @param array<string, \WP_Post_Type> $post_types Registered post type objects, keyed by name.
     *
     * @return array<string, \WP_Post_Type>
     */
    public static function exclude_video_from_core_sitemap(array $post_types): array
    {
        unset($post_types['video']);

        return $post_types;
    }

    /**
     * Append this plugin's own video sitemap to robots.txt's `Sitemap:`
     * directive(s) — see self::boot()'s docblock comment for why.
     *
     * @param string $output    The robots.txt output so far.
     * @param bool   $is_public Whether `blog_public` allows indexing.
     */
    public static function add_video_sitemap_to_robots_txt(string $output, bool $is_public): string
    {
        if (! $is_public) {
            return $output;
        }

        return $output . "\nSitemap: " . esc_url(home_url('/video-sitemap.xml')) . "\n";
    }

    /**
     * Send `X-Robots-Tag: noindex, nofollow` on every REST API response —
     * see self::boot()'s docblock comment for why.
     *
     * @param bool $served Whether the request has already been served — passed through unchanged.
     */
    public static function add_rest_x_robots_tag_header(bool $served): bool
    {
        if (! headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow');
        }

        return $served;
    }

    /**
     * Register this plugin's WP-CLI commands, if WP-CLI is the current runtime.
     */
    private function register_cli_commands(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        $sitemap_command = new SitemapCommand($this->sitemap_generator());

        WP_CLI::add_command('tube-seo sitemap:generate', [$sitemap_command, 'generate']);
    }
}
