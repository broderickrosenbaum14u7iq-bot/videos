<?php
/**
 * A computed set of per-page SEO meta values.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Meta;

/**
 * A computed set of per-page SEO meta values — title, description,
 * canonical URL, robots directive, and the OpenGraph/Twitter Card image
 * — the shape `PageMetaBuilder`'s per-page-type factory methods return.
 * Plain text values, never pre-escaped: `Tube_Seo\Head\SeoHead` (the
 * WordPress-coupled renderer) is responsible for `esc_html()`/
 * `esc_attr()`/`esc_url()` at the point each value is actually output.
 */
final class PageMeta
{
    /**
     * Construct an immutable set of page meta values.
     *
     * @param string      $title       The `<title>` text.
     * @param string      $description The meta description text.
     * @param string      $canonical   The canonical URL.
     * @param string      $robots      The robots directive, e.g. `'index, follow'`.
     * @param string      $og_type     The OpenGraph `og:type` value, e.g. `'video.other'`/`'website'`.
     * @param string|null $image_url   The OpenGraph/Twitter Card image URL, if any.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $canonical,
        public readonly string $robots,
        public readonly string $og_type,
        public readonly ?string $image_url
    ) {
    }
}
