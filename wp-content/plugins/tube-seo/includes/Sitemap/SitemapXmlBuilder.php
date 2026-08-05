<?php
/**
 * Builds video sitemap XML (and sitemap-index XML) from already-resolved data.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Sitemap;

use DOMDocument;
use DOMElement;

/**
 * Builds video sitemap XML (and sitemap-index XML) from already-resolved
 * data, per the sitemaps.org protocol plus Google's video sitemap
 * extension (`http://www.google.com/schemas/sitemap-video/1.1`). Pure
 * structural XML building given typed inputs — no WordPress calls, no
 * database access, fully unit-tested. Uses `DOMDocument` (a native PHP
 * extension, not a WordPress dependency) rather than string
 * concatenation specifically so every text value is correctly XML-escaped
 * by construction, not by a hand-written `htmlspecialchars()` call that
 * could be forgotten on one field.
 */
final class SitemapXmlBuilder
{
    /**
     * The sitemaps.org namespace every `<urlset>`/`<sitemapindex>` root uses.
     */
    private const SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /**
     * Google's video sitemap extension namespace.
     */
    private const VIDEO_NS = 'http://www.google.com/schemas/sitemap-video/1.1';

    /**
     * Build one sitemap file's XML for a list of video entries.
     *
     * @param VideoSitemapEntry[] $entries The videos this sitemap file covers.
     */
    public function build_urlset(array $entries): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');

        $urlset = $document->createElementNS(self::SITEMAP_NS, 'urlset');
        $urlset->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:video', self::VIDEO_NS);
        $document->appendChild($urlset);

        foreach ($entries as $entry) {
            $urlset->appendChild($this->build_url_element($document, $entry));
        }

        return $this->to_string($document);
    }

    /**
     * Build the sitemap-index XML listing every shard's URL.
     *
     * @param list<array{loc: string, lastmod: string}> $sitemaps One entry per shard, in the order they'll be listed.
     */
    public function build_index(array $sitemaps): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');

        $index = $document->createElementNS(self::SITEMAP_NS, 'sitemapindex');
        $document->appendChild($index);

        foreach ($sitemaps as $sitemap) {
            $entry = $document->createElement('sitemap');
            $entry->appendChild($this->text_element($document, 'loc', $sitemap['loc']));
            $entry->appendChild($this->text_element($document, 'lastmod', $sitemap['lastmod']));
            $index->appendChild($entry);
        }

        return $this->to_string($document);
    }

    /**
     * Build one `<url>` element (with its nested `<video:video>`) for one entry.
     *
     * @param DOMDocument       $document The document new elements are created against.
     * @param VideoSitemapEntry $entry   The entry to render.
     */
    private function build_url_element(DOMDocument $document, VideoSitemapEntry $entry): DOMElement
    {
        $url = $document->createElement('url');
        $url->appendChild($this->text_element($document, 'loc', $entry->loc));
        $url->appendChild($this->text_element($document, 'lastmod', $entry->lastmod));

        $video = $document->createElementNS(self::VIDEO_NS, 'video:video');
        $video->appendChild(
            $this->text_element($document, 'video:thumbnail_loc', $entry->thumbnail_loc, self::VIDEO_NS)
        );
        $video->appendChild($this->text_element($document, 'video:title', $entry->title, self::VIDEO_NS));
        $video->appendChild($this->text_element($document, 'video:description', $entry->description, self::VIDEO_NS));
        $video->appendChild($this->text_element($document, 'video:player_loc', $entry->player_loc, self::VIDEO_NS));
        $video->appendChild(
            $this->text_element($document, 'video:publication_date', $entry->publication_date, self::VIDEO_NS)
        );

        if (null !== $entry->duration_seconds) {
            $video->appendChild(
                $this->text_element($document, 'video:duration', (string) $entry->duration_seconds, self::VIDEO_NS)
            );
        }

        $url->appendChild($video);

        return $url;
    }

    /**
     * Create one element with a text-node child — never `DOMDocument::
     * createElement()`'s own `$value` third argument, which requires the
     * caller to pre-escape XML-significant characters itself; a real
     * `createTextNode()` child is escaped correctly by the DOM on
     * serialization regardless of what the text contains.
     *
     * @param DOMDocument $document       The document to create the element against.
     * @param string      $qualified_name The (possibly namespace-prefixed) element name.
     * @param string      $text           The element's text content.
     * @param string|null $namespace_uri      The element's namespace URI, or null for the default (sitemap) namespace.
     */
    private function text_element(
        DOMDocument $document,
        string $qualified_name,
        string $text,
        ?string $namespace_uri = null
    ): DOMElement {
        $element = null === $namespace_uri
            ? $document->createElement($qualified_name)
            : $document->createElementNS($namespace_uri, $qualified_name);

        $element->appendChild($document->createTextNode($text));

        return $element;
    }

    /**
     * Serialize a built document to an XML string.
     *
     * @param DOMDocument $document The document to serialize.
     */
    private function to_string(DOMDocument $document): string
    {
        $xml = $document->saveXML();

        return false === $xml ? '' : $xml;
    }
}
