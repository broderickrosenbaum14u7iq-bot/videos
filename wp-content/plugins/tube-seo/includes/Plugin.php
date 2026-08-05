<?php
/**
 * Tube SEO's bootstrap.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo;

use Tube_Seo\Head\SeoHead;

/**
 * Tube SEO's bootstrap — a single lazy accessor for `SeoHead`, the
 * composition-root shape every other tube-* plugin already uses.
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
     * Empty: unlike every other tube-* plugin, tube-seo has nothing to
     * self-hook — `tube_seo_head()` is called explicitly by the theme
     * inside `<head>`, not triggered by a WordPress action. Kept for
     * consistency with the composition-root shape every other plugin
     * uses, and as the place a future hook-driven concern (e.g. sitemap
     * generation, Phase 9's remaining scope) would be wired.
     */
    public function boot(): void
    {
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
}
