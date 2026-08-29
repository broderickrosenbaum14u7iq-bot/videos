<?php
/**
 * Integration tests for SeoHead's real output on real page types.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Tests\Integration\Head;

use PHPUnit\Framework\TestCase;
use Tube_Core\Plugin as Tube_Core_Plugin;
use Tube_Core\Video\CfStreamStatus;
use Tube_Seo\Admin\HomepageSeoSettings;
use Tube_Seo\Plugin as Tube_Seo_Plugin;
use WP_Query;
use WP_Term;

/**
 * Exercises `SeoHead::render()` (via `tube_seo_head()`) against real
 * WordPress query state for a video page and a category archive page —
 * proving the page-type detection cascade and the real title/canonical/
 * JSON-LD output, not just the pure builders it delegates to (already
 * unit-tested against fakes).
 */
final class SeoHeadIntegrationTest extends TestCase
{
    /**
     * Video posts created by a test, cleaned up in tearDown() regardless of outcome.
     *
     * @var int[]
     */
    private array $created_video_ids = [];

    /**
     * `video_category` terms created by a test, cleaned up in tearDown().
     *
     * @var int[]
     */
    private array $created_term_ids = [];

    /**
     * `video_tag` terms created by a test, cleaned up in tearDown().
     *
     * @var int[]
     */
    private array $created_tag_ids = [];

    /**
     * Delete every video post/term created by the test, and restore the global query.
     */
    protected function tearDown(): void
    {
        foreach ($this->created_video_ids as $video_id) {
            wp_delete_post($video_id, true);
        }

        foreach ($this->created_term_ids as $term_id) {
            wp_delete_term($term_id, 'video_category');
        }

        foreach ($this->created_tag_ids as $tag_id) {
            wp_delete_term($tag_id, 'video_tag');
        }

        $this->created_video_ids = [];
        $this->created_term_ids  = [];
        $this->created_tag_ids   = [];

        wp_reset_postdata();
    }

    /**
     * On a real video single page, tube_seo_head() emits the video's own
     * title, self-canonical URL, and a VideoObject JSON-LD block with
     * the real Cloudflare Stream embed URL.
     */
    public function test_video_page_emits_title_canonical_and_video_object_json_ld(): void
    {
        $video_id = $this->create_published_video('Seo Head Integration Test Video');

        Tube_Core_Plugin::instance()->video_metadata_repository()->create(
            $video_id,
            'test-cf-uid-' . $video_id,
            CfStreamStatus::Ready
        );

        $this->simulate_singular_video($video_id);

        $output = $this->capture_head();

        self::assertStringContainsString('<title>Seo Head Integration Test Video', $output);
        self::assertStringContainsString('rel="canonical"', $output);
        self::assertStringContainsString('"@type":"VideoObject"', $output);
        self::assertStringContainsString('test-cf-uid-' . $video_id, $output);
    }

    /**
     * On a real R2/direct-MP4 video's single page, the VideoObject
     * JSON-LD's `embedUrl` is the video's own watch-page permalink —
     * never the permanent R2 media URL (`media.nangcuctvc.com`), which
     * must not be exposed through SEO output now that the R2 bucket is
     * private behind a Cloudflare Worker and only reachable through a
     * short-lived signed URL.
     */
    public function test_r2_video_page_json_ld_does_not_expose_the_permanent_media_url(): void
    {
        $video_id = $this->create_published_video('Seo Head R2 Integration Test Video');

        Tube_Core_Plugin::instance()->video_metadata_repository()->create_r2(
            $video_id,
            'seo-head-r2-test-video.mp4',
            CfStreamStatus::Ready
        );

        $this->simulate_singular_video($video_id);

        $output = $this->capture_head();

        self::assertStringContainsString('"@type":"VideoObject"', $output);
        self::assertStringNotContainsString('media.nangcuctvc.com', $output);
        // wp_json_encode() escapes '/' to '\/' by default -- match the
        // permalink as it actually appears inside the JSON-LD block.
        $expected_embed_url = str_replace('/', '\\/', (string) get_permalink($video_id));
        self::assertStringContainsString('"embedUrl":"' . $expected_embed_url . '"', $output);
    }

    /**
     * ADR-0001: when a video has a WordPress Media Library OG-image
     * override set, tube_seo_head() uses that attachment's URL for
     * og:image/JSON-LD thumbnailUrl instead of the Cloudflare Stream
     * thumbnail — closing a pre-existing gap where SeoHead always used
     * the Stream thumbnail directly, regardless of any stored override.
     */
    public function test_video_page_og_image_honors_media_library_override(): void
    {
        $video_id = $this->create_published_video('Seo Head OG Override Test Video');

        Tube_Core_Plugin::instance()->video_metadata_repository()->create(
            $video_id,
            'test-cf-uid-og-' . $video_id,
            CfStreamStatus::Ready
        );

        $attachment_id = $this->create_test_attachment('Seo Head OG Override Test Attachment');

        try {
            Tube_Core_Plugin::instance()->video_metadata_repository()->update_images(
                $video_id,
                null,
                $attachment_id
            );

            $this->simulate_singular_video($video_id);

            $output = $this->capture_head();

            $expected_url = wp_get_attachment_image_url($attachment_id, [1200, 630]);
            self::assertIsString($expected_url);
            self::assertStringContainsString(esc_url($expected_url), $output);
        } finally {
            wp_delete_attachment($attachment_id, true);
        }
    }

    /**
     * 2026-08-26 SEO audit P0 fix: when a video has no OG-image override
     * set (og_image_id is null — true for every real video under the
     * current PosterImageMetaBox admin flow, which only ever writes
     * poster_image_id), tube_seo_head() falls back to the poster image
     * rather than omitting og:image/twitter:image/thumbnailUrl entirely.
     */
    public function test_video_page_og_image_falls_back_to_poster_image_when_no_og_override_set(): void
    {
        $video_id = $this->create_published_video('Seo Head Poster Fallback Test Video');

        Tube_Core_Plugin::instance()->video_metadata_repository()->create(
            $video_id,
            'test-cf-uid-poster-fallback-' . $video_id,
            CfStreamStatus::Ready
        );

        $attachment_id = $this->create_test_attachment('Seo Head Poster Fallback Test Attachment');

        try {
            Tube_Core_Plugin::instance()->video_metadata_repository()->update_images(
                $video_id,
                $attachment_id,
                null
            );

            $this->simulate_singular_video($video_id);

            $output = $this->capture_head();

            $expected_url = wp_get_attachment_image_url($attachment_id, [1200, 630]);
            self::assertIsString($expected_url);
            self::assertStringContainsString(esc_url($expected_url), $output);
            self::assertStringContainsString('"thumbnailUrl"', $output);
        } finally {
            wp_delete_attachment($attachment_id, true);
        }
    }

    /**
     * On a real category archive page, tube_seo_head() emits a
     * CollectionPage JSON-LD block and a noindex robots tag when the
     * archive has no videos yet.
     */
    public function test_empty_category_archive_is_noindexed_with_collection_page_json_ld(): void
    {
        $term_id = $this->create_category();

        $this->simulate_category_archive($term_id);

        $output = $this->capture_head();

        self::assertStringContainsString('"@type":"CollectionPage"', $output);
        self::assertStringContainsString('name="robots" content="noindex, follow"', $output);
    }

    /**
     * 2026-08-26 SEO audit P1 fix: a tag with fewer than the thin-tag
     * threshold's worth of real published videos is noindexed even
     * though its archive page isn't empty — free-text tags routinely
     * land at 1-2 videos, which is thin/near-duplicate content.
     */
    public function test_thin_tag_archive_is_noindexed_despite_having_a_video(): void
    {
        $video_id = $this->create_published_video('Seo Head Thin Tag Test Video');
        $tag_id   = $this->create_tag();

        wp_set_post_terms($video_id, [$tag_id], 'video_tag');
        // wp_set_post_terms() alone doesn't fire save_post, which is what
        // tube-search's reindex subscriber listens for — a bare resave
        // forces it to pick up the tag just assigned above.
        wp_update_post(['ID' => $video_id]);

        $this->simulate_tag_archive($tag_id);

        $output = $this->capture_head();

        self::assertStringContainsString('name="robots" content="noindex, follow"', $output);
    }

    /**
     * A tag with at least the thin-tag threshold's worth of real
     * published videos stays indexable.
     */
    public function test_tag_archive_with_enough_videos_stays_indexed(): void
    {
        $tag_id = $this->create_tag();

        for ($i = 0; $i < 3; $i++) {
            $video_id = $this->create_published_video("Seo Head Non-Thin Tag Test Video {$i}");
            wp_set_post_terms($video_id, [$tag_id], 'video_tag');
            wp_update_post(['ID' => $video_id]);
        }

        $this->simulate_tag_archive($tag_id);

        $output = $this->capture_head();

        self::assertStringContainsString('name="robots" content="index, follow"', $output);
    }

    /**
     * 2026-08-26 SEO audit P2: the homepage emits exactly one WebSite
     * JSON-LD block, using the real site name/URL, with no fabricated
     * publisher/potentialAction.
     */
    public function test_front_page_emits_website_json_ld(): void
    {
        $this->simulate_front_page();

        $output = $this->capture_head();

        self::assertSame(1, substr_count($output, '"@type":"WebSite"'));
        self::assertStringContainsString('"url":"' . str_replace('/', '\/', home_url('/')) . '"', $output);
        self::assertStringNotContainsString('"publisher"', $output);
        self::assertStringNotContainsString('"potentialAction"', $output);
    }

    /**
     * 2026-08-26 Homepage SEO controls, Test A: with both custom values
     * configured, they become the authoritative title/description —
     * exactly one of each of title/description/og:title/og:description/
     * twitter:title/twitter:description, all carrying the custom values.
     */
    public function test_front_page_uses_custom_title_and_description_when_configured(): void
    {
        update_option(HomepageSeoSettings::OPTION_TITLE, 'Test Primary Keyword - Test Brand');
        update_option(HomepageSeoSettings::OPTION_DESCRIPTION, 'This is a test homepage SEO description.');

        try {
            $this->simulate_front_page();

            $output = $this->capture_head();

            self::assertSame(1, substr_count($output, '<title>'));
            self::assertSame(1, substr_count($output, 'name="description"'));
            self::assertSame(1, substr_count($output, 'property="og:title"'));
            self::assertSame(1, substr_count($output, 'property="og:description"'));
            self::assertSame(1, substr_count($output, 'name="twitter:title"'));
            self::assertSame(1, substr_count($output, 'name="twitter:description"'));

            self::assertStringContainsString(
                '<title>Test Primary Keyword - Test Brand</title>',
                $output
            );
            self::assertStringContainsString(
                'name="description" content="This is a test homepage SEO description.">',
                $output
            );
            self::assertStringContainsString(
                'property="og:title" content="Test Primary Keyword - Test Brand">',
                $output
            );
            self::assertStringContainsString(
                'property="og:description" content="This is a test homepage SEO description.">',
                $output
            );
            self::assertStringContainsString(
                'name="twitter:title" content="Test Primary Keyword - Test Brand">',
                $output
            );
            self::assertStringContainsString(
                'name="twitter:description" content="This is a test homepage SEO description.">',
                $output
            );
        } finally {
            delete_option(HomepageSeoSettings::OPTION_TITLE);
            delete_option(HomepageSeoSettings::OPTION_DESCRIPTION);
        }
    }

    /**
     * 2026-08-26 Homepage SEO controls, Test B: with both custom values
     * empty/deleted (the default state), the homepage behaves exactly as
     * it did before this feature existed — title falls back to the site
     * name, description falls back to the WordPress tagline.
     */
    public function test_front_page_falls_back_to_site_name_and_tagline_when_unconfigured(): void
    {
        delete_option(HomepageSeoSettings::OPTION_TITLE);
        delete_option(HomepageSeoSettings::OPTION_DESCRIPTION);

        $this->simulate_front_page();

        $output = $this->capture_head();

        self::assertSame(1, substr_count($output, '<title>'));
        self::assertSame(1, substr_count($output, 'name="description"'));
        self::assertStringContainsString('<title>' . get_bloginfo('name'), $output);
        self::assertStringContainsString(
            'name="description" content="' . esc_attr(get_bloginfo('description')) . '">',
            $output
        );
    }

    /**
     * 2026-08-26 Homepage SEO controls, Test C: special characters
     * (`&`, `"`, `<`, Vietnamese Unicode) are escaped exactly once —
     * valid rendered HTML, not double-escaped, not unescaped.
     */
    public function test_front_page_custom_values_are_escaped_exactly_once(): void
    {
        $raw_title       = 'Phim & "Tối Cổ" <Video> - Xem Miễn Phí';
        $raw_description = 'Mô tả có ký tự đặc biệt: & " < Tiếng Việt có dấu.';

        update_option(HomepageSeoSettings::OPTION_TITLE, $raw_title);
        update_option(HomepageSeoSettings::OPTION_DESCRIPTION, $raw_description);

        try {
            $this->simulate_front_page();

            $output = $this->capture_head();

            self::assertStringContainsString('<title>' . esc_html($raw_title) . '</title>', $output);
            self::assertStringContainsString(
                'name="description" content="' . esc_attr($raw_description) . '">',
                $output
            );
            // Not double-escaped: esc_html()/esc_attr() each run exactly
            // once would never produce a literal "&amp;amp;".
            self::assertStringNotContainsString('&amp;amp;', $output);
            // A real, unescaped "<Video>" would break the <title> element
            // itself — assert the parsed DOM still has exactly one title
            // node with the full expected text, proving it round-trips as
            // real text content, not raw markup.
            $document              = new \DOMDocument();
            $previous_libxml_state = libxml_use_internal_errors(true);
            // DOMDocument::loadHTML() assumes Latin-1 without an explicit
            // encoding declaration, mangling every non-ASCII byte — this
            // XML prolog forces it to parse as UTF-8, the same fix
            // php.net's own loadHTML() docs recommend.
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $output);
            libxml_clear_errors();
            libxml_use_internal_errors($previous_libxml_state);

            self::assertTrue($loaded);
            self::assertSame(1, $document->getElementsByTagName('title')->length);

            $title_node = $document->getElementsByTagName('title')->item(0);
            self::assertNotNull($title_node);
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native PHP DOMNode property, not this project's naming to control.
            self::assertSame($raw_title, $title_node->textContent);
        } finally {
            delete_option(HomepageSeoSettings::OPTION_TITLE);
            delete_option(HomepageSeoSettings::OPTION_DESCRIPTION);
        }
    }

    /**
     * 2026-08-26 Homepage SEO controls, Test D: configuring homepage SEO
     * settings must not change SEO output on any other page type.
     */
    public function test_homepage_seo_settings_do_not_affect_other_page_types(): void
    {
        update_option(HomepageSeoSettings::OPTION_TITLE, 'Should Never Appear On A Video Page');
        update_option(HomepageSeoSettings::OPTION_DESCRIPTION, 'Should Never Appear On A Video Page Either');

        try {
            $video_id = $this->create_published_video('Seo Head Homepage Setting Isolation Test Video');

            Tube_Core_Plugin::instance()->video_metadata_repository()->create(
                $video_id,
                'test-cf-uid-homepage-isolation-' . $video_id,
                CfStreamStatus::Ready
            );

            $this->simulate_singular_video($video_id);

            $output = $this->capture_head();

            self::assertStringNotContainsString('Should Never Appear On A Video Page', $output);
            self::assertStringContainsString(
                '<title>Seo Head Homepage Setting Isolation Test Video',
                $output
            );
        } finally {
            delete_option(HomepageSeoSettings::OPTION_TITLE);
            delete_option(HomepageSeoSettings::OPTION_DESCRIPTION);
        }
    }

    /**
     * 2026-08-26 Homepage SEO controls, Test E: WebSite.name stays the
     * real site name (get_bloginfo('name')) even when a custom, longer
     * Homepage SEO Title is configured — the two are distinct concepts.
     */
    public function test_website_schema_name_is_unaffected_by_custom_homepage_title(): void
    {
        update_option(HomepageSeoSettings::OPTION_TITLE, 'Test Primary Keyword - Test Brand - Extra Words');

        try {
            $this->simulate_front_page();

            $output = $this->capture_head();

            // The custom title legitimately appears elsewhere on the page
            // (<title>, og:title, twitter:title) — this asserts only that
            // the WebSite JSON-LD block's own "name" is the real site
            // name, not the (deliberately different, longer) SEO title.
            // wp_json_encode() escapes non-ASCII as \uXXXX, so the
            // expected fragment is built the same way, not compared
            // against the raw UTF-8 site name directly.
            self::assertStringContainsString(
                '"@type":"WebSite","name":' . (string) wp_json_encode(get_bloginfo('name')),
                $output
            );
            self::assertStringNotContainsString('"@type":"WebSite","name":"Test Primary Keyword', $output);
        } finally {
            delete_option(HomepageSeoSettings::OPTION_TITLE);
        }
    }

    /**
     * 2026-08-26 SEO audit P2: with no verification tokens configured
     * (the real, current state — nothing in this plugin sets these
     * options), neither meta tag is emitted at all.
     */
    public function test_verification_tags_are_absent_when_unset(): void
    {
        $this->simulate_front_page();

        $output = $this->capture_head();

        self::assertStringNotContainsString('google-site-verification', $output);
        self::assertStringNotContainsString('msvalidate.01', $output);
    }

    /**
     * When a real token is configured (via `update_option()` — this
     * plugin has no admin UI for it, see SeoHead::render_verification_tags()),
     * the corresponding meta tag is emitted, escaped.
     */
    public function test_verification_tags_are_emitted_when_configured(): void
    {
        update_option('tube_seo_google_site_verification', 'fake-test-token-for-this-assertion-only');
        update_option('tube_seo_bing_site_verification', 'another-fake-test-token');

        try {
            $this->simulate_front_page();

            $output = $this->capture_head();

            self::assertStringContainsString(
                '<meta name="google-site-verification" content="fake-test-token-for-this-assertion-only">',
                $output
            );
            self::assertStringContainsString(
                '<meta name="msvalidate.01" content="another-fake-test-token">',
                $output
            );
        } finally {
            delete_option('tube_seo_google_site_verification');
            delete_option('tube_seo_bing_site_verification');
        }
    }

    /**
     * Capture tube_seo_head()'s echoed output.
     */
    private function capture_head(): string
    {
        ob_start();
        Tube_Seo_Plugin::instance()->head()->render();

        return (string) ob_get_clean();
    }

    /**
     * Set up the global query state as if the current request were `/watch/{slug}/` for this video.
     *
     * @param int $video_id The video post ID.
     */
    private function simulate_singular_video(int $video_id): void
    {
        global $wp_query, $post;

        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberately simulating a real request's query state, the same thing WP core's own go_to() test helper does; this project has no such helper.
        $wp_query = new WP_Query(
            [
                'p'         => $video_id,
                'post_type' => 'video',
            ]
        );
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- see comment above.
        $post = get_post($video_id);

        self::assertNotNull($post);
        setup_postdata($post);
    }

    /**
     * Set up the global query state as if the current request were the homepage (`/`).
     */
    private function simulate_front_page(): void
    {
        global $wp_query;

        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberately simulating a real request's query state, the same thing WP core's own go_to() test helper does; this project has no such helper.
        $wp_query          = new WP_Query();
        $wp_query->is_home = true;
    }

    /**
     * Set up the global query state as if the current request were `/category/{slug}/` for this term.
     *
     * @param int $term_id The `video_category` term ID.
     */
    private function simulate_category_archive(int $term_id): void
    {
        global $wp_query;

        $term = get_term($term_id, 'video_category');
        self::assertInstanceOf(WP_Term::class, $term);

        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberately simulating a real request's query state, the same thing WP core's own go_to() test helper does; this project has no such helper.
        $wp_query = new WP_Query(
            [
                'taxonomy' => 'video_category',
                'term'     => $term->slug,
            ]
        );
    }

    /**
     * Create a real published video post, tracked for teardown.
     *
     * @param string $title The post title.
     */
    private function create_published_video(string $title): int
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

        return $video_id;
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
        $filename   = 'tube-seo-test-' . uniqid('', true) . '.png';
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
     * Create a real `video_category` term, tracked for teardown.
     */
    private function create_category(): int
    {
        $result = wp_insert_term('Seo Head Integration Test Category ' . uniqid('', true), 'video_category');

        self::assertIsArray($result);

        $term_id                  = (int) $result['term_id'];
        $this->created_term_ids[] = $term_id;

        return $term_id;
    }

    /**
     * Create a real `video_tag` term, tracked for teardown.
     */
    private function create_tag(): int
    {
        $result = wp_insert_term('seo-head-thin-tag-test-' . uniqid('', true), 'video_tag');

        self::assertIsArray($result);

        $tag_id                  = (int) $result['term_id'];
        $this->created_tag_ids[] = $tag_id;

        return $tag_id;
    }

    /**
     * Set up the global query state as if the current request were `/tag/{slug}/` for this term.
     *
     * @param int $tag_id The `video_tag` term ID.
     */
    private function simulate_tag_archive(int $tag_id): void
    {
        global $wp_query;

        $term = get_term($tag_id, 'video_tag');
        self::assertInstanceOf(WP_Term::class, $term);

        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberately simulating a real request's query state, the same thing WP core's own go_to() test helper does; this project has no such helper.
        $wp_query = new WP_Query(
            [
                'taxonomy' => 'video_tag',
                'term'     => $term->slug,
            ]
        );
    }
}
