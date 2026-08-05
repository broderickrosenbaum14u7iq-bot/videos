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

        $this->created_video_ids = [];
        $this->created_term_ids  = [];

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
}
