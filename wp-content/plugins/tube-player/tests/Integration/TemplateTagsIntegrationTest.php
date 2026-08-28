<?php
/**
 * Integration tests for tube-player's template tags, against real
 * WordPress and a real tube-core video.
 *
 * @package Tube_Player
 */

declare(strict_types=1);

namespace Tube_Player\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tube_Core\Plugin as Tube_Core_Plugin;
use Tube_Core\Video\CfStreamStatus;
use Tube_Player\Plugin as Tube_Player_Plugin;
use Tube_Player\Render\ProfileImageHtmlRenderer;
use Tube_Player\Video\Cloudflare\CloudflareImagesUrlBuilder;
use Tube_Player\Video\ImageSize;

/**
 * Exercises `tube_player_get_image_html()`/`tube_player_get_embed_html()`
 * end-to-end: a real video post, a real `wp_tube_video_metadata` row
 * (via tube-core's own repository), real `esc_url()`/`esc_attr()`
 * output. Expected URLs are computed by calling the real
 * `Tube_Player\Plugin::instance()->video_provider()` directly rather
 * than hard-coding a customer-code value — this stays correct regardless
 * of what `TUBE_PLAYER_CLOUDFLARE_STREAM_CUSTOMER_CODE` is configured to
 * in whatever environment runs this suite.
 */
final class TemplateTagsIntegrationTest extends TestCase
{
    /**
     * A real published video created for the test.
     *
     * @var int
     */
    private int $video_id;

    /**
     * The Cloudflare Stream UID seeded for the test video.
     *
     * @var string
     */
    private string $cf_stream_uid;

    /**
     * Create a real published video with a real wp_tube_video_metadata row.
     */
    protected function setUp(): void
    {
        $video_id = wp_insert_post(
            [
                'post_type'   => 'video',
                'post_title'  => 'Template Tags Integration Test Video',
                'post_status' => 'publish',
            ],
            true
        );

        self::assertIsInt($video_id);
        $this->video_id = $video_id;

        $this->cf_stream_uid = 'uid-' . uniqid('', true);

        Tube_Core_Plugin::instance()->video_metadata_repository()->create(
            $this->video_id,
            $this->cf_stream_uid,
            CfStreamStatus::Ready
        );
    }

    /**
     * Delete the video and its metadata row.
     *
     * @throws RuntimeException If the query template is malformed (a bug in this method, not in any argument).
     */
    protected function tearDown(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $sql = $wpdb->prepare(
            'DELETE FROM %i WHERE video_id = %d',
            $wpdb->prefix . 'tube_video_metadata',
            $this->video_id
        );

        if (null === $sql) {
            throw new RuntimeException(
                'wpdb::prepare() returned null for the metadata cleanup query in ' . self::class . '.'
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test cleanup against a dedicated custom table owned by tube-core; $sql *is* $wpdb->prepare()'d above.
        $wpdb->query($sql);

        wp_delete_post($this->video_id, true);
    }

    /**
     * ADR-0001's 2026-08-25 addendum: a video with no WordPress Media
     * Library poster override set renders no `<img>` at all — there is
     * no Cloudflare Stream thumbnail fallback to render instead.
     */
    public function test_image_html_returns_empty_string_when_no_poster_override_is_set(): void
    {
        self::assertSame('', tube_player_get_image_html($this->video_id, 'grid_card'));
    }

    /**
     * With a WordPress Media Library poster override set, the image tag
     * carries the expected src, explicit dimensions, and default (lazy) loading attributes.
     */
    public function test_image_html_renders_expected_attributes_for_default_call(): void
    {
        $attachment_id = self::create_test_attachment();

        try {
            Tube_Core_Plugin::instance()->video_metadata_repository()->update_images(
                $this->video_id,
                $attachment_id,
                null
            );

            $html = tube_player_get_image_html($this->video_id, 'grid_card');

            $expected_src = wp_get_attachment_image_url($attachment_id, [320, 180]);
            self::assertIsString($expected_src);

            self::assertStringStartsWith('<img ', $html);
            self::assertStringContainsString('src="' . esc_url($expected_src) . '"', $html);
            self::assertStringContainsString('width="320"', $html);
            self::assertStringContainsString('height="180"', $html);
            self::assertStringContainsString('loading="lazy"', $html);
            self::assertStringContainsString('fetchpriority="auto"', $html);
        } finally {
            wp_delete_attachment($attachment_id, true);
        }
    }

    /**
     * `eager` in $args produces eager loading and high fetch priority — for an above-the-fold instance.
     */
    public function test_image_html_eager_arg_sets_loading_eager_and_high_priority(): void
    {
        $attachment_id = self::create_test_attachment();

        try {
            Tube_Core_Plugin::instance()->video_metadata_repository()->update_images(
                $this->video_id,
                $attachment_id,
                null
            );

            $html = tube_player_get_image_html($this->video_id, 'hero', ['eager' => true]);

            self::assertStringContainsString('loading="eager"', $html);
            self::assertStringContainsString('fetchpriority="high"', $html);
        } finally {
            wp_delete_attachment($attachment_id, true);
        }
    }

    /**
     * An unknown video ID renders nothing, not a broken tag.
     */
    public function test_image_html_returns_empty_string_for_unknown_video(): void
    {
        self::assertSame('', tube_player_get_image_html(999999999, 'grid_card'));
    }

    /**
     * An unrecognized $size renders nothing.
     */
    public function test_image_html_returns_empty_string_for_unrecognized_size(): void
    {
        self::assertSame('', tube_player_get_image_html($this->video_id, 'not-a-real-size'));
    }

    /**
     * ADR-0001: when a video has a WordPress Media Library poster
     * override set, that attachment's own URL is used.
     */
    public function test_image_html_uses_media_library_poster_when_override_is_set(): void
    {
        $attachment_id = self::create_test_attachment();

        try {
            Tube_Core_Plugin::instance()->video_metadata_repository()->update_images(
                $this->video_id,
                $attachment_id,
                null
            );

            $html = tube_player_get_image_html($this->video_id, 'grid_card');

            $expected_src = wp_get_attachment_image_url($attachment_id, [320, 180]);

            self::assertIsString($expected_src);
            self::assertStringContainsString('src="' . esc_url($expected_src) . '"', $html);
        } finally {
            wp_delete_attachment($attachment_id, true);
        }
    }

    /**
     * ADR-0001's 2026-08-25 addendum: a poster override pointing at a
     * deleted/invalid attachment ID renders no `<img>` at all — there is
     * no Cloudflare Stream thumbnail fallback to gracefully degrade to
     * anymore, so a broken reference behaves exactly like "no override
     * set" rather than silently substituting different content.
     */
    public function test_image_html_returns_empty_string_for_invalid_override(): void
    {
        Tube_Core_Plugin::instance()->video_metadata_repository()->update_images(
            $this->video_id,
            999999999,
            null
        );

        self::assertSame('', tube_player_get_image_html($this->video_id, 'grid_card'));
    }

    /**
     * Create a real `attachment` post backed by a real 1x1 PNG file with
     * real generated `_wp_attachment_metadata` (via `wp_generate_attachment_metadata()`)
     * — the same shape a genuine `wp.media()` upload produces. A bare
     * `wp_insert_post( ['post_type' => 'attachment', ...] )` with no
     * underlying file/metadata is not sufficient: `wp_get_attachment_image_url()`
     * legitimately returns `false` for an array `$size` when no
     * attachment metadata exists to resolve it against (the same
     * graceful-degradation path `ImageHtmlRenderer::resolve_urls()` falls
     * through to the Cloudflare Stream default for), so this fixture must
     * produce a genuinely resolvable attachment to test the override path
     * itself, distinct from {@see self::test_image_html_falls_back_to_stream_thumbnail_for_invalid_override()}'s
     * deliberately-broken-reference case.
     */
    private static function create_test_attachment(): int
    {
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload_dir = wp_upload_dir();
        $filename   = 'tube-player-test-' . uniqid('', true) . '.png';
        $file_path  = trailingslashit($upload_dir['path']) . $filename;

        // A real, minimal 1x1 transparent PNG.
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
                'post_title'     => 'Template Tags Integration Test Attachment',
                'post_status'    => 'inherit',
            ],
            $file_path
        );

        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $file_path));

        return $attachment_id;
    }

    /**
     * The click-to-load block carries the real embed URL, a keyboard-accessible play
     * button with a title-aware aria-label, and a working noscript fallback — with no
     * WordPress Media Library poster override set, it renders with no `<img>` (ADR-0001's
     * 2026-08-25 addendum: no Cloudflare Stream thumbnail fallback), not a broken one.
     */
    public function test_embed_html_renders_the_click_to_load_block(): void
    {
        $html = tube_player_get_embed_html($this->video_id, ['title' => 'My Test Video']);

        $expected_embed_url = Tube_Player_Plugin::instance()->video_provider()->embed_url($this->cf_stream_uid);
        $expected_view_url  = rest_url('tube/v1/videos/' . $this->video_id . '/view');

        self::assertStringContainsString('data-tube-player', $html);
        self::assertStringContainsString('data-embed-url="' . esc_url($expected_embed_url) . '"', $html);
        self::assertStringContainsString('data-view-url="' . esc_url($expected_view_url) . '"', $html);
        self::assertStringContainsString('<button type="button"', $html);
        self::assertStringContainsString('aria-label="Play video: My Test Video"', $html);
        self::assertStringNotContainsString('<img ', $html);
        self::assertStringContainsString('<noscript>', $html);
        self::assertStringContainsString(esc_url($expected_embed_url), $html);
    }

    /**
     * With a WordPress Media Library poster override set, the click-to-load
     * block's poster `<img>` uses that attachment (ADR-0001).
     */
    public function test_embed_html_renders_the_poster_when_a_media_library_override_is_set(): void
    {
        $attachment_id = self::create_test_attachment();

        try {
            Tube_Core_Plugin::instance()->video_metadata_repository()->update_images(
                $this->video_id,
                $attachment_id,
                null
            );

            $html = tube_player_get_embed_html($this->video_id, ['title' => 'My Test Video']);

            $hero_size    = [ImageSize::Hero->width(), ImageSize::Hero->height()];
            $expected_src = wp_get_attachment_image_url($attachment_id, $hero_size);
            self::assertIsString($expected_src);

            self::assertStringContainsString('<img ', $html);
            self::assertStringContainsString('src="' . esc_url($expected_src) . '"', $html);
        } finally {
            wp_delete_attachment($attachment_id, true);
        }
    }

    /**
     * An unknown video ID renders nothing.
     */
    public function test_embed_html_returns_empty_string_for_unknown_video(): void
    {
        self::assertSame('', tube_player_get_embed_html(999999999));
    }

    /**
     * Phase 13: a null image ID (no photo) renders nothing, not a broken
     * tag — the common case, since `Actor::$photo_image_id`/
     * `Studio::$logo_image_id` are usually null until an editor uploads one.
     */
    public function test_profile_image_html_returns_empty_string_for_null_image_id(): void
    {
        self::assertSame('', tube_player_get_profile_image_html(null));
    }

    /**
     * Phase 13: an unrecognized $size renders nothing.
     */
    public function test_profile_image_html_returns_empty_string_for_unrecognized_size(): void
    {
        self::assertSame('', tube_player_get_profile_image_html(123, 'not-a-real-size'));
    }

    /**
     * Phase 13: this staging environment has no
     * TUBE_PLAYER_CLOUDFLARE_IMAGES_ACCOUNT_HASH configured, so
     * Tube_Player\Plugin::instance()->images_url_builder() is null and
     * the template tag correctly degrades to '' even for a real image
     * ID — the same graceful-degradation `ImageHtmlRenderer`'s own
     * override path already relies on when Cloudflare Images isn't set up.
     */
    public function test_profile_image_html_returns_empty_string_when_images_url_builder_unconfigured(): void
    {
        self::assertSame('', tube_player_get_profile_image_html(123));
    }

    /**
     * Phase 13: with a Cloudflare Images URL builder actually configured
     * (constructed directly here, independent of this environment's own
     * `TUBE_PLAYER_CLOUDFLARE_IMAGES_ACCOUNT_HASH` config, so this test
     * is deterministic regardless of environment), a real image ID
     * renders the expected `<img>` markup: correct src, square
     * dimensions, no srcset.
     */
    public function test_profile_image_renderer_renders_expected_markup_when_configured(): void
    {
        $renderer = new ProfileImageHtmlRenderer(new CloudflareImagesUrlBuilder('test-account-hash'));

        $html = $renderer->render(456, ImageSize::Avatar, ['alt' => 'Jane Doe']);

        self::assertSame(
            '<img src="https://imagedelivery.net/test-account-hash/456/avatar" width="400" height="400"'
                . ' alt="Jane Doe" loading="lazy" decoding="async" class="tube-player__profile-photo" />',
            $html
        );
    }

    /**
     * Phase 13: a configured renderer still returns '' for a null image ID.
     */
    public function test_profile_image_renderer_returns_empty_string_for_null_image_id_even_when_configured(): void
    {
        $renderer = new ProfileImageHtmlRenderer(new CloudflareImagesUrlBuilder('test-account-hash'));

        self::assertSame('', $renderer->render(null, ImageSize::Avatar));
    }
}
