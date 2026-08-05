<?php
/**
 * Tube SEO's bootstrap.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo;

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
