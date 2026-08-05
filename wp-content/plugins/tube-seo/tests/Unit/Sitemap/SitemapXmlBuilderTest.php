<?php
/**
 * Unit tests for SitemapXmlBuilder.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Tests\Unit\Sitemap;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Tube_Seo\Sitemap\SitemapXmlBuilder;
use Tube_Seo\Sitemap\VideoSitemapEntry;

/**
 * Exercises SitemapXmlBuilder's pure XML-structure building — no WordPress.
 */
final class SitemapXmlBuilderTest extends TestCase
{
    /**
     * The builder under test.
     *
     * @var SitemapXmlBuilder
     */
    private SitemapXmlBuilder $builder;

    /**
     * Build a fresh builder for each test.
     */
    protected function setUp(): void
    {
        $this->builder = new SitemapXmlBuilder();
    }

    /**
     * Build_urlset() produces one <url>/<video:video> block per entry, with the documented tags and values.
     */
    public function test_build_urlset_produces_the_documented_shape(): void
    {
        $xml = $this->builder->build_urlset([$this->entry(1, 'Video One')]);

        $xpath = $this->xpath($xml);

        self::assertSame(1, $this->node_count($xpath, '/s:urlset/s:url'));
        self::assertSame('https://example.com/watch/video-1/', $this->text($xpath, '//s:url/s:loc'));
        self::assertSame('2026-01-01T00:00:00+00:00', $this->text($xpath, '//s:url/s:lastmod'));
        self::assertSame('https://example.com/thumb/1.jpg', $this->text($xpath, '//v:video/v:thumbnail_loc'));
        self::assertSame('Video One', $this->text($xpath, '//v:video/v:title'));
        self::assertSame('A description.', $this->text($xpath, '//v:video/v:description'));
        self::assertSame('https://example.com/embed/1', $this->text($xpath, '//v:video/v:player_loc'));
        self::assertSame('2026-01-01T00:00:00+00:00', $this->text($xpath, '//v:video/v:publication_date'));
        self::assertSame('120', $this->text($xpath, '//v:video/v:duration'));
    }

    /**
     * An empty entry list produces a valid, empty <urlset>, not an error.
     */
    public function test_build_urlset_with_no_entries_produces_an_empty_urlset(): void
    {
        $xml = $this->builder->build_urlset([]);

        $xpath = $this->xpath($xml);

        self::assertSame(0, $this->node_count($xpath, '/s:urlset/s:url'));
    }

    /**
     * Multiple entries each produce their own <url> block, in order.
     */
    public function test_build_urlset_with_multiple_entries(): void
    {
        $xml = $this->builder->build_urlset([$this->entry(1, 'Video One'), $this->entry(2, 'Video Two')]);

        $xpath = $this->xpath($xml);

        self::assertSame(2, $this->node_count($xpath, '/s:urlset/s:url'));
    }

    /**
     * A null duration omits <video:duration> entirely, rather than emitting an empty tag.
     */
    public function test_null_duration_omits_the_duration_element(): void
    {
        $entry = new VideoSitemapEntry(
            1,
            'https://example.com/watch/video-one/',
            '2026-01-01T00:00:00+00:00',
            'Video One',
            'A description.',
            'https://example.com/thumb/1.jpg',
            'https://example.com/embed/1',
            '2026-01-01T00:00:00+00:00',
            null
        );

        $xml   = $this->builder->build_urlset([$entry]);
        $xpath = $this->xpath($xml);

        self::assertSame(0, $this->node_count($xpath, '//v:video/v:duration'));
    }

    /**
     * Title/description text containing XML-significant characters is safely escaped, not corrupted.
     */
    public function test_special_characters_are_safely_escaped(): void
    {
        $entry = $this->entry(1, 'Rock & Roll <Live> "Show"');

        $xml   = $this->builder->build_urlset([$entry]);
        $xpath = $this->xpath($xml);

        self::assertSame('Rock & Roll <Live> "Show"', $this->text($xpath, '//v:video/v:title'));
        // The raw serialized XML must never contain a literal, unescaped "<Live>" as markup.
        self::assertStringNotContainsString('<Live>', $xml);
    }

    /**
     * Build_index() produces one <sitemap> entry per shard, with loc/lastmod.
     */
    public function test_build_index_produces_the_documented_shape(): void
    {
        $xml = $this->builder->build_index(
            [
                [
                    'loc'     => 'https://example.com/video-sitemap-1.xml',
                    'lastmod' => '2026-01-01T00:00:00+00:00',
                ],
                [
                    'loc'     => 'https://example.com/video-sitemap-2.xml',
                    'lastmod' => '2026-01-02T00:00:00+00:00',
                ],
            ]
        );

        $document = new DOMDocument();
        $document->loadXML($xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        self::assertSame(2, $this->node_count($xpath, '/s:sitemapindex/s:sitemap'));
        self::assertSame(
            'https://example.com/video-sitemap-1.xml',
            $this->text($xpath, '//s:sitemap[1]/s:loc')
        );
    }

    /**
     * Build a standard test entry.
     *
     * @param int    $video_id The video post ID.
     * @param string $title    The video's title.
     */
    private function entry(int $video_id, string $title): VideoSitemapEntry
    {
        return new VideoSitemapEntry(
            $video_id,
            "https://example.com/watch/video-{$video_id}/",
            '2026-01-01T00:00:00+00:00',
            $title,
            'A description.',
            "https://example.com/thumb/{$video_id}.jpg",
            "https://example.com/embed/{$video_id}",
            '2026-01-01T00:00:00+00:00',
            120
        );
    }

    /**
     * Parse an XML string and return an XPath evaluator with both namespaces registered.
     *
     * @param string $xml The XML string to parse.
     */
    private function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument();
        $document->loadXML($xml);

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xpath->registerNamespace('v', 'http://www.google.com/schemas/sitemap-video/1.1');

        return $xpath;
    }

    /**
     * Evaluate an XPath expression and return the first match's text content.
     *
     * @param DOMXPath $xpath      The evaluator to query.
     * @param string   $expression The XPath expression.
     */
    private function text(DOMXPath $xpath, string $expression): string
    {
        $node = $this->query($xpath, $expression)->item(0);

        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native PHP DOMNode property, not this project's naming to control.
        return $node instanceof \DOMNode ? (string) $node->textContent : '';
    }

    /**
     * Evaluate an XPath expression and return how many nodes matched.
     *
     * @param DOMXPath $xpath      The evaluator to query.
     * @param string   $expression The XPath expression.
     */
    private function node_count(DOMXPath $xpath, string $expression): int
    {
        return $this->query($xpath, $expression)->length;
    }

    /**
     * Evaluate an XPath expression, failing the test immediately if the expression itself is malformed.
     *
     * @param DOMXPath $xpath      The evaluator to query.
     * @param string   $expression The XPath expression.
     *
     * @return \DOMNodeList<\DOMNameSpaceNode|\DOMNode>
     */
    private function query(DOMXPath $xpath, string $expression): \DOMNodeList
    {
        $result = $xpath->query($expression);

        self::assertNotFalse($result, "Malformed XPath expression: {$expression}");

        return $result;
    }
}
