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
     * The image tag carries the expected src, explicit dimensions, and default (lazy) loading attributes.
     */
    public function test_image_html_renders_expected_attributes_for_default_call(): void
    {
        $html = tube_player_get_image_html($this->video_id, 'grid_card');

        $expected_src = Tube_Player_Plugin::instance()->video_provider()->thumbnail_url(
            $this->cf_stream_uid,
            0,
            320,
            180
        );

        self::assertStringStartsWith('<img ', $html);
        self::assertStringContainsString('src="' . esc_url($expected_src) . '"', $html);
        self::assertStringContainsString('width="320"', $html);
        self::assertStringContainsString('height="180"', $html);
        self::assertStringContainsString('loading="lazy"', $html);
        self::assertStringContainsString('fetchpriority="auto"', $html);
    }

    /**
     * `eager` in $args produces eager loading and high fetch priority — for an above-the-fold instance.
     */
    public function test_image_html_eager_arg_sets_loading_eager_and_high_priority(): void
    {
        $html = tube_player_get_image_html($this->video_id, 'hero', ['eager' => true]);

        self::assertStringContainsString('loading="eager"', $html);
        self::assertStringContainsString('fetchpriority="high"', $html);
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
     * The click-to-load block carries the real embed URL, a keyboard-accessible play
     * button with a title-aware aria-label, and a working noscript fallback.
     */
    public function test_embed_html_renders_the_click_to_load_block(): void
    {
        $html = tube_player_get_embed_html($this->video_id, ['title' => 'My Test Video']);

        $expected_embed_url = Tube_Player_Plugin::instance()->video_provider()->embed_url($this->cf_stream_uid);

        self::assertStringContainsString('data-tube-player', $html);
        self::assertStringContainsString('data-embed-url="' . esc_url($expected_embed_url) . '"', $html);
        self::assertStringContainsString('<button type="button"', $html);
        self::assertStringContainsString('aria-label="Play video: My Test Video"', $html);
        self::assertStringContainsString('<img ', $html);
        self::assertStringContainsString('<noscript>', $html);
        self::assertStringContainsString(esc_url($expected_embed_url), $html);
    }

    /**
     * An unknown video ID renders nothing.
     */
    public function test_embed_html_returns_empty_string_for_unknown_video(): void
    {
        self::assertSame('', tube_player_get_embed_html(999999999));
    }
}
