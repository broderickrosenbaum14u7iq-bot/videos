<?php
/**
 * Builds the schema.org BreadcrumbList JSON-LD structure.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\JsonLd;

/**
 * Builds the schema.org `BreadcrumbList` JSON-LD structure, per this
 * phase's explicit SEO deliverable. Pure array-building logic given an
 * already-ordered list of (name, url) pairs — no WordPress calls, fully
 * unit-tested.
 */
final class BreadcrumbListBuilder
{
    /**
     * Build the BreadcrumbList structure.
     *
     * @param list<array{name: string, url: string}> $items Breadcrumb items, home page first.
     *
     * @return array<string, mixed>
     *
     * @phpstan-return array{
     *     '@context': string,
     *     '@type': string,
     *     itemListElement: list<array{'@type': string, position: int, name: string, item: string}>
     * }
     */
    public static function build(array $items): array
    {
        $list_items = [];
        $position   = 1;

        foreach ($items as $item) {
            $list_items[] = [
                '@type'    => 'ListItem',
                'position' => $position,
                'name'     => $item['name'],
                'item'     => $item['url'],
            ];

            ++$position;
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $list_items,
        ];
    }
}
