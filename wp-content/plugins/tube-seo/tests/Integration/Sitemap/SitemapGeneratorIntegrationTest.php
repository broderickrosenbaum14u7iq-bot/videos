<?php
/**
 * Integration tests for the sitemap generation pipeline against real seeded videos.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Tests\Integration\Sitemap;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Tube_Core\Plugin as Tube_Core_Plugin;
use Tube_Core\Video\CfStreamStatus;
use Tube_Seo\Plugin as Tube_Seo_Plugin;
use Tube_Seo\Sitemap\SitemapGenerator;

/**
 * Exercises `SitemapGenerator::generate()` end to end against real
 * `wp_insert_post()`-created videos and real `VideoMetadataRepository`
 * rows — the full gather/build/write pipeline `SitemapXmlBuilder` (unit-
 * tested against fakes) and `PublishedVideoRepository` (a thin `$wpdb`
 * read, no independent logic worth faking) are only meaningfully
 * verified together, against a real database and a real filesystem.
 */
final class SitemapGeneratorIntegrationTest extends TestCase
{
    /**
     * Video posts created by a test, cleaned up in tearDown() regardless of outcome.
     *
     * @var int[]
     */
    private array $created_video_ids = [];

    /**
     * Reset the generator's own state and remove any files it wrote, so each test starts clean.
     */
    protected function setUp(): void
    {
        delete_option('tube_seo_sitemap_state');
        $this->delete_generated_files();
    }

    /**
     * Delete every video post created by the test, and this run's generated state/files.
     */
    protected function tearDown(): void
    {
        foreach ($this->created_video_ids as $video_id) {
            wp_delete_post($video_id, true);
        }

        $this->created_video_ids = [];

        delete_option('tube_seo_sitemap_state');
        $this->delete_generated_files();
    }

    /**
     * A small set of ready videos produces one video-sitemap.xml with a matching <url> per video.
     */
    public function test_generate_writes_a_single_shard_for_a_small_video_set(): void
    {
        $first  = $this->create_ready_video('Sitemap Integration Video One');
        $second = $this->create_ready_video('Sitemap Integration Video Two');

        $result = $this->generator()->generate();

        self::assertTrue($result->regenerated);
        self::assertSame(1, $result->shard_count);
        self::assertSame(2, $result->video_count);

        $path = trailingslashit(SitemapGenerator::directory()) . 'video-sitemap.xml';
        self::assertFileExists($path);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this test's own locally-generated file, not a remote URL.
        $contents = (string) file_get_contents($path);
        $xpath    = $this->xpath($contents);
        self::assertSame(2, $this->node_count($xpath, '/s:urlset/s:url'));

        $titles = [
            $this->text($xpath, '//v:video[v:title="Sitemap Integration Video One"]/v:title'),
            $this->text($xpath, '//v:video[v:title="Sitemap Integration Video Two"]/v:title'),
        ];
        self::assertSame(['Sitemap Integration Video One', 'Sitemap Integration Video Two'], $titles);

        self::assertFalse(file_exists(trailingslashit(SitemapGenerator::directory()) . 'video-sitemap-index.xml'));

        unset($first, $second);
    }

    /**
     * A video with no Cloudflare Stream metadata row yet is excluded, not rendered with empty URLs.
     */
    public function test_generate_excludes_videos_without_metadata(): void
    {
        $video_id = wp_insert_post(
            [
                'post_type'   => 'video',
                'post_title'  => 'Sitemap Integration Video Without Metadata',
                'post_status' => 'publish',
            ],
            true
        );
        self::assertIsInt($video_id);
        $this->created_video_ids[] = $video_id;

        $result = $this->generator()->generate();

        self::assertTrue($result->regenerated);
        self::assertSame(0, $result->video_count);
    }

    /**
     * A second run with nothing changed since the first is skipped.
     */
    public function test_generate_skips_when_nothing_changed_since_the_last_run(): void
    {
        $this->create_ready_video('Sitemap Integration Incremental Video');

        $first = $this->generator()->generate();
        self::assertTrue($first->regenerated);

        $second = $this->generator()->generate();
        self::assertFalse($second->regenerated);
        self::assertSame(0, $second->shard_count);
    }

    /**
     * Passing $force = true regenerates even when nothing has changed.
     */
    public function test_force_regenerates_even_when_nothing_changed(): void
    {
        $this->create_ready_video('Sitemap Integration Force Video');

        $first = $this->generator()->generate();
        self::assertTrue($first->regenerated);

        $second = $this->generator()->generate(true);
        self::assertTrue($second->regenerated);
        self::assertSame(1, $second->video_count);
    }

    /**
     * Publishing a new video after a run makes the next run regenerate again (the incremental check notices).
     */
    public function test_generate_regenerates_after_a_new_video_is_published(): void
    {
        $this->create_ready_video('Sitemap Integration First Video');
        $first = $this->generator()->generate();
        self::assertTrue($first->regenerated);

        $this->create_ready_video('Sitemap Integration Second Video');
        $second = $this->generator()->generate();

        self::assertTrue($second->regenerated);
        self::assertSame(2, $second->video_count);
    }

    /**
     * When the URLs-per-sitemap ceiling is filtered down below the video count, generation shards and writes an index.
     */
    public function test_generate_shards_and_writes_an_index_when_over_the_url_limit(): void
    {
        $this->create_ready_video('Sitemap Integration Shard Video One');
        $this->create_ready_video('Sitemap Integration Shard Video Two');
        $this->create_ready_video('Sitemap Integration Shard Video Three');

        $limiter = static fn (): int => 1;
        add_filter('tube_seo_sitemap_urls_per_sitemap', $limiter);

        try {
            $result = $this->generator()->generate();
        } finally {
            remove_filter('tube_seo_sitemap_urls_per_sitemap', $limiter);
        }

        self::assertTrue($result->regenerated);
        self::assertSame(3, $result->shard_count);
        self::assertSame(3, $result->video_count);

        $directory = SitemapGenerator::directory();
        self::assertFileExists(trailingslashit($directory) . 'video-sitemap-1.xml');
        self::assertFileExists(trailingslashit($directory) . 'video-sitemap-2.xml');
        self::assertFileExists(trailingslashit($directory) . 'video-sitemap-3.xml');
        self::assertFileDoesNotExist(trailingslashit($directory) . 'video-sitemap.xml');

        $index_path = trailingslashit($directory) . 'video-sitemap-index.xml';
        self::assertFileExists($index_path);

        $document = new DOMDocument();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this test's own locally-generated file, not a remote URL.
        $index_contents = (string) file_get_contents($index_path);
        $document->loadXML($index_contents);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        self::assertSame(3, $this->node_count($xpath, '/s:sitemapindex/s:sitemap'));
    }

    /**
     * A run that produces fewer files than the previous run (fewer shards, or no index at all
     * anymore) deletes the previous run's now-stale extra files, rather than leaving them
     * lingering on disk (and still publicly served at their old URLs with outdated content).
     */
    public function test_generate_deletes_stale_files_left_over_from_a_larger_previous_run(): void
    {
        $this->create_ready_video('Sitemap Integration Shrink Video One');
        $this->create_ready_video('Sitemap Integration Shrink Video Two');

        $limiter = static fn (): int => 1;
        add_filter('tube_seo_sitemap_urls_per_sitemap', $limiter);

        try {
            $sharded = $this->generator()->generate();
        } finally {
            remove_filter('tube_seo_sitemap_urls_per_sitemap', $limiter);
        }

        self::assertSame(2, $sharded->shard_count);

        $directory = SitemapGenerator::directory();
        self::assertFileExists(trailingslashit($directory) . 'video-sitemap-1.xml');
        self::assertFileExists(trailingslashit($directory) . 'video-sitemap-2.xml');
        self::assertFileExists(trailingslashit($directory) . 'video-sitemap-index.xml');

        $unsharded = $this->generator()->generate(true);

        self::assertSame(1, $unsharded->shard_count);
        self::assertFileExists(trailingslashit($directory) . 'video-sitemap.xml');
        self::assertFileDoesNotExist(trailingslashit($directory) . 'video-sitemap-1.xml');
        self::assertFileDoesNotExist(trailingslashit($directory) . 'video-sitemap-2.xml');
        self::assertFileDoesNotExist(trailingslashit($directory) . 'video-sitemap-index.xml');
    }

    /**
     * The generator under test, via the real Plugin composition root.
     */
    private function generator(): SitemapGenerator
    {
        return Tube_Seo_Plugin::instance()->sitemap_generator();
    }

    /**
     * Create a real published video post with ready Cloudflare Stream metadata, tracked for teardown.
     *
     * @param string $title The post title.
     */
    private function create_ready_video(string $title): int
    {
        $video_id = wp_insert_post(
            [
                'post_type'   => 'video',
                'post_title'  => $title,
                'post_status' => 'publish',
            ],
            true
        );
        self::assertIsInt($video_id);
        $this->created_video_ids[] = $video_id;

        Tube_Core_Plugin::instance()->video_metadata_repository()->create(
            $video_id,
            'sitemap-test-cf-uid-' . $video_id,
            CfStreamStatus::Ready
        );

        return $video_id;
    }

    /**
     * Remove any sitemap files a prior run (in this test or a previous one) left behind.
     */
    private function delete_generated_files(): void
    {
        $directory = SitemapGenerator::directory();

        if (! is_dir($directory)) {
            return;
        }

        $files = glob(trailingslashit($directory) . 'video-sitemap*.xml');

        foreach (false === $files ? [] : $files as $file) {
            wp_delete_file($file);
        }
    }

    /**
     * Parse an XML string and return an XPath evaluator with both sitemap namespaces registered.
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
        $result = $xpath->query($expression);
        self::assertNotFalse($result, "Malformed XPath expression: {$expression}");

        $node = $result->item(0);

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
        $result = $xpath->query($expression);
        self::assertNotFalse($result, "Malformed XPath expression: {$expression}");

        return $result->length;
    }
}
