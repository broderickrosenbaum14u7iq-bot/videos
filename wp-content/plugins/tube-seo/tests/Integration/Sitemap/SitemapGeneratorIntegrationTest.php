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
     * Attachment posts created by a test (via self::create_ready_video()'s default OG-image
     * attachment or a test's own self::create_test_attachment() call), cleaned up in tearDown().
     *
     * @var int[]
     */
    private array $created_attachment_ids = [];

    /**
     * Reset the generator's own state and remove any files it wrote, so each test starts clean.
     */
    protected function setUp(): void
    {
        delete_option('tube_seo_sitemap_state');
        $this->delete_generated_files();
    }

    /**
     * Delete every video/attachment post created by the test, and this run's generated state/files.
     */
    protected function tearDown(): void
    {
        foreach ($this->created_video_ids as $video_id) {
            wp_delete_post($video_id, true);
        }

        foreach ($this->created_attachment_ids as $attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }

        $this->created_video_ids      = [];
        $this->created_attachment_ids = [];

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
        // >=, not ===: this suite runs against the real site database
        // (tests/Integration/bootstrap.php), and the 2026-08-26 SEO audit
        // P0 fix (poster_image_id fallback) means real, ambient published
        // videos now legitimately qualify for inclusion too — this test
        // only cares that its own 2 videos are present (checked by title
        // below), not the total.
        self::assertGreaterThanOrEqual(2, $result->video_count);

        $path = trailingslashit(SitemapGenerator::directory()) . 'video-sitemap.xml';
        self::assertFileExists($path);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this test's own locally-generated file, not a remote URL.
        $contents = (string) file_get_contents($path);
        $xpath    = $this->xpath($contents);
        self::assertGreaterThanOrEqual(2, $this->node_count($xpath, '/s:urlset/s:url'));

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
        $title    = 'Sitemap Integration Video Without Metadata';
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

        $result = $this->generator()->generate();

        self::assertTrue($result->regenerated);
        self::assertFalse($this->sitemap_contains_title($title));
    }

    /**
     * A video with real Cloudflare Stream metadata but no WordPress Media
     * Library OG-image set is also excluded — ADR-0001's 2026-08-25
     * addendum removed the Cloudflare Stream thumbnail fallback
     * entirely, and Google's video sitemap protocol requires a real
     * `<video:thumbnail_loc>`, not a fabricated one.
     */
    public function test_generate_excludes_videos_without_a_media_library_og_image(): void
    {
        $title    = 'Sitemap Integration Video Without OG Image';
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
            'sitemap-test-cf-uid-no-og-image-' . $video_id,
            CfStreamStatus::Ready
        );

        $result = $this->generator()->generate();

        self::assertTrue($result->regenerated);
        self::assertFalse($this->sitemap_contains_title($title));
    }

    /**
     * 2026-08-26 SEO audit P0 fix: a video with real Cloudflare Stream
     * metadata and a poster_image_id but no og_image_id override is
     * still included, using the poster image as its <video:thumbnail_loc>
     * — og_image_id is null for every real video under the current
     * PosterImageMetaBox admin flow, so requiring it alone excluded 100%
     * of real published videos from this sitemap.
     */
    public function test_generate_includes_a_video_using_poster_image_when_no_og_image_is_set(): void
    {
        $title    = 'Sitemap Integration Video With Poster Only';
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
            'sitemap-test-cf-uid-poster-only-' . $video_id,
            CfStreamStatus::Ready
        );

        $attachment_id                  = $this->create_test_attachment('Sitemap Poster-Only Test Image');
        $this->created_attachment_ids[] = $attachment_id;

        Tube_Core_Plugin::instance()->video_metadata_repository()->update_images($video_id, $attachment_id, null);

        $result = $this->generator()->generate();

        self::assertTrue($result->regenerated);

        $path = trailingslashit(SitemapGenerator::directory()) . 'video-sitemap.xml';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this test's own locally-generated file, not a remote URL.
        $contents = (string) file_get_contents($path);
        $xpath    = $this->xpath($contents);

        $expected_url = wp_get_attachment_image_url($attachment_id, [1200, 630]);
        self::assertIsString($expected_url);
        self::assertSame(
            $expected_url,
            $this->text($xpath, "//v:video[v:title=\"{$title}\"]/v:thumbnail_loc")
        );
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
        $title = 'Sitemap Integration Force Video';
        $this->create_ready_video($title);

        $first = $this->generator()->generate();
        self::assertTrue($first->regenerated);

        $second = $this->generator()->generate(true);
        self::assertTrue($second->regenerated);
        self::assertGreaterThanOrEqual(1, $second->video_count);
        self::assertTrue($this->sitemap_contains_title($title));
    }

    /**
     * Publishing a new video after a run makes the next run regenerate again (the incremental check notices).
     */
    public function test_generate_regenerates_after_a_new_video_is_published(): void
    {
        $this->create_ready_video('Sitemap Integration First Video');
        $first = $this->generator()->generate();
        self::assertTrue($first->regenerated);
        $first_count = $first->video_count;

        $second_title = 'Sitemap Integration Second Video';
        $this->create_ready_video($second_title);
        $second = $this->generator()->generate();

        self::assertTrue($second->regenerated);
        self::assertSame($first_count + 1, $second->video_count);
        self::assertTrue($this->sitemap_contains_title($second_title));
    }

    /**
     * When the URLs-per-sitemap ceiling is filtered down below the video count, generation shards and writes an index.
     */
    public function test_generate_shards_and_writes_an_index_when_over_the_url_limit(): void
    {
        $restore_ambient = $this->suppress_ambient_published_videos();

        $this->create_ready_video('Sitemap Integration Shard Video One');
        $this->create_ready_video('Sitemap Integration Shard Video Two');
        $this->create_ready_video('Sitemap Integration Shard Video Three');

        $limiter = static fn (): int => 1;
        add_filter('tube_seo_sitemap_urls_per_sitemap', $limiter);

        try {
            $result = $this->generator()->generate();
        } finally {
            remove_filter('tube_seo_sitemap_urls_per_sitemap', $limiter);
            $restore_ambient();
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
        $restore_ambient = $this->suppress_ambient_published_videos();

        try {
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
        } finally {
            $restore_ambient();
        }
    }

    /**
     * ADR-0001: a video with a WordPress Media Library OG-image override
     * gets that attachment's URL as its sitemap `<video:thumbnail_loc>`,
     * not the Cloudflare Stream thumbnail — closing a pre-existing gap
     * where the sitemap always used the Stream thumbnail directly.
     */
    public function test_generate_honors_media_library_og_image_override_as_thumbnail(): void
    {
        $title    = 'Sitemap Integration OG Override Video';
        $video_id = $this->create_ready_video($title);

        $attachment_id = $this->create_test_attachment('Sitemap Integration OG Override Attachment');

        try {
            Tube_Core_Plugin::instance()->video_metadata_repository()->update_images(
                $video_id,
                null,
                $attachment_id
            );

            $result = $this->generator()->generate();
            self::assertTrue($result->regenerated);

            $path = trailingslashit(SitemapGenerator::directory()) . 'video-sitemap.xml';
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this test's own locally-generated file, not a remote URL.
            $contents = (string) file_get_contents($path);
            $xpath    = $this->xpath($contents);

            $expected_url = wp_get_attachment_image_url($attachment_id, [1200, 630]);
            self::assertIsString($expected_url);
            self::assertSame(
                $expected_url,
                $this->text($xpath, "//v:video[v:title=\"{$title}\"]/v:thumbnail_loc")
            );
        } finally {
            wp_delete_attachment($attachment_id, true);
        }
    }

    /**
     * Create a real `attachment` post backed by a real 1x1 PNG file with
     * real generated `_wp_attachment_metadata` — the same shape a genuine
     * `wp.media()` upload produces. A bare `wp_insert_post()` with no
     * underlying file/metadata is not sufficient: `wp_get_attachment_image_url()`
     * legitimately returns `false` for an array `$size` with no
     * attachment metadata to resolve against.
     *
     * @param string $title The attachment's post title.
     */
    private function create_test_attachment(string $title): int
    {
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload_dir = wp_upload_dir();
        $filename   = 'tube-seo-sitemap-test-' . uniqid('', true) . '.png';
        $file_path  = trailingslashit($upload_dir['path']) . $filename;

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a fixed, hardcoded test-fixture image, not obfuscation.
        $png_bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );
        self::assertIsString($png_bytes);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture writing to this run's own uploads dir, not a runtime request path.
        file_put_contents($file_path, $png_bytes);

        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => 'image/png',
                'post_title'     => $title,
                'post_status'    => 'inherit',
            ],
            $file_path
        );

        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $file_path));

        return $attachment_id;
    }

    /**
     * Whether the currently-generated video-sitemap.xml contains a `<video>` entry with this title.
     *
     * @param string $title The video title to look for.
     */
    private function sitemap_contains_title(string $title): bool
    {
        $path = trailingslashit(SitemapGenerator::directory()) . 'video-sitemap.xml';

        if (! file_exists($path)) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this test's own locally-generated file, not a remote URL.
        $contents = (string) file_get_contents($path);
        $xpath    = $this->xpath($contents);

        return '' !== $this->text($xpath, "//v:video[v:title=\"{$title}\"]/v:title");
    }

    /**
     * Temporarily unpublish every real, ambient published video not
     * created by this test — this suite runs against the actual site
     * database (tests/Integration/bootstrap.php loads a real
     * wp-load.php), and the 2026-08-26 SEO audit P0 fix (SitemapGenerator
     * falling back to poster_image_id) means real pre-existing videos
     * now legitimately qualify for sitemap inclusion, which breaks any
     * test asserting an exact total shard/video count. Only the two
     * tests that genuinely need a controlled total (sharding math) use
     * this; every other test instead asserts by specific title, which
     * stays correct regardless of ambient data.
     *
     * Uses a raw `$wpdb->update()` against `post_status` directly, NOT
     * `wp_update_post()` — deliberately. `wp_update_post()` fires the
     * full `save_post`/`transition_post_status` hook stack, which
     * includes tube-search's own real-video reindex-on-publish
     * subscriber; that subscriber was found (2026-08-26, while writing
     * this fix) to reset a real video's `wp_tube_search_index.views_total`
     * to 0 on every status transition — a real, pre-existing tube-search
     * bug, unrelated to and out of scope for this SEO fix, but one this
     * suppression helper must not trigger against real videos at all. A
     * direct SQL status flip changes exactly the one column
     * `PublishedVideoRepository`'s own raw `$wpdb` read cares about,
     * firing no hooks and touching no other column.
     *
     * @return callable(): void Call (in a `finally` block) to restore every affected post's original status.
     */
    private function suppress_ambient_published_videos(): callable
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off test-isolation read, not production code.
        $ambient_ids       = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT ID FROM %i WHERE post_type = %s AND post_status = %s',
                $wpdb->posts,
                'video',
                'publish'
            )
        );
        $typed_ambient_ids = [];

        foreach ($ambient_ids as $id) {
            if (is_numeric($id)) {
                $typed_ambient_ids[] = (int) $id;
            }
        }

        $ambient_ids = $typed_ambient_ids;

        foreach ($ambient_ids as $id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberate: see this method's own docblock for why wp_update_post() must not be used here.
            $wpdb->update($wpdb->posts, ['post_status' => 'draft'], ['ID' => $id]);
            clean_post_cache($id);
        }

        return static function () use ($ambient_ids, $wpdb): void {
            foreach ($ambient_ids as $id) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberate: see this method's own docblock for why wp_update_post() must not be used here.
                $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $id]);
                clean_post_cache($id);
            }
        };
    }

    /**
     * The generator under test, via the real Plugin composition root.
     */
    private function generator(): SitemapGenerator
    {
        return Tube_Seo_Plugin::instance()->sitemap_generator();
    }

    /**
     * Create a real published video post with ready Cloudflare Stream
     * metadata AND a real WordPress Media Library OG-image attachment
     * (tracked for teardown), tracked for teardown — sitemap-eligible per
     * self::build_entries()'s "not ready" gate (ADR-0001's 2026-08-25
     * addendum: no Cloudflare Stream thumbnail fallback, so a video with
     * no OG image is excluded — see
     * self::test_generate_excludes_videos_without_a_media_library_og_image()
     * for that case specifically). Every other test in this class cares
     * about sharding/incremental/count behavior, not the thumbnail
     * resolution itself, so a real default attachment here keeps them
     * exercising that behavior without being blocked by this gate.
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

        $attachment_id                  = $this->create_test_attachment($title . ' Default OG Image');
        $this->created_attachment_ids[] = $attachment_id;

        Tube_Core_Plugin::instance()->video_metadata_repository()->update_images($video_id, null, $attachment_id);

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
