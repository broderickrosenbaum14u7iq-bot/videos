<?php
/**
 * Builds the schema.org CollectionPage JSON-LD structure for a listing/archive page.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\JsonLd;

/**
 * Builds the schema.org `CollectionPage` JSON-LD structure for a
 * listing/archive page (category, tag, actor, studio, search, trending,
 * most-viewed, latest), per this phase's explicit SEO deliverable. Pure
 * array-building logic given already-resolved scalar inputs — no
 * WordPress calls, fully unit-tested.
 */
final class CollectionPageBuilder
{
    /**
     * Build the CollectionPage structure.
     *
     * @param string $name        The listing's title (e.g. the category name).
     * @param string $url         The canonical URL of this listing page.
     * @param int    $item_count  The total number of items in this listing, across all pages.
     *
     * @return array<string, mixed>
     *
     * @phpstan-return array{
     *     '@context': string,
     *     '@type': string,
     *     name: string,
     *     url: string,
     *     mainEntity: array{'@type': string, numberOfItems: int}
     * }
     */
    public static function build(string $name, string $url, int $item_count): array
    {
        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'CollectionPage',
            'name'       => $name,
            'url'        => $url,
            'mainEntity' => [
                '@type'         => 'ItemList',
                'numberOfItems' => $item_count,
            ],
        ];
    }
}
