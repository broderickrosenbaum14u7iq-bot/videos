<?php
/**
 * Builds the schema.org WebSite JSON-LD structure for the homepage.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\JsonLd;

/**
 * Builds the schema.org `WebSite` JSON-LD structure for the homepage
 * (2026-08-26 SEO audit P2 finding — no WebSite entity existed anywhere
 * in the codebase). Deliberately minimal: only `name` and `url`, both
 * always resolvable from real site configuration
 * (`get_bloginfo('name')`/`home_url('/')`) — no `potentialAction`
 * (schema.org `SearchAction`; Google deprecated the Sitelinks Search Box
 * feature it powered in November 2024, and this project's search URL
 * shape, `/search/{query}/`, was never wired up to it) and no
 * `publisher` (would need a real `Organization` entity backed by actual
 * business data — logo, social profiles — none of which exists in this
 * site's configuration; see `Tube_Seo\Head\SeoHead`'s own docblock for
 * the full reasoning). Pure array-building logic given already-resolved
 * scalar inputs — no WordPress calls, fully unit-tested.
 */
final class WebSiteBuilder
{
    /**
     * Build the WebSite structure.
     *
     * @param string $name The site's name.
     * @param string $url  The site's homepage URL.
     *
     * @return array<string, mixed>
     *
     * @phpstan-return array{'@context': string, '@type': string, name: string, url: string}
     */
    public static function build(string $name, string $url): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => $name,
            'url'      => $url,
        ];
    }
}
