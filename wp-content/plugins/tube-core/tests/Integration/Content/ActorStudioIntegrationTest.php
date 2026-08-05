<?php
/**
 * Integration tests for ActorRepository/StudioRepository and archive routing.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Integration\Content;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tube_Core\Plugin as Tube_Core_Plugin;
use WP_Query;

/**
 * Exercises `ActorRepository`/`StudioRepository` against the real
 * `wp_tube_actors`/`wp_tube_video_actors`/`wp_tube_studios`/
 * `wp_tube_video_studios` tables, and `TermArchiveRouting`'s real
 * `add_rewrite_rule()`/`template_include` wiring against a real request.
 *
 * No write API exists yet for actors/studios (that's `tube-admin`'s,
 * Phase 10) — rows are seeded directly via `$wpdb`, the same "seed
 * directly, verify the read path" approach `RelatedVideosIntegrationTest`
 * (tube-search, Phase 7) already established for the same reason.
 */
final class ActorStudioIntegrationTest extends TestCase
{
    /**
     * Actor IDs created by a test, cleaned up in tearDown().
     *
     * @var int[]
     */
    private array $created_actor_ids = [];

    /**
     * Studio IDs created by a test, cleaned up in tearDown().
     *
     * @var int[]
     */
    private array $created_studio_ids = [];

    /**
     * Video posts created by a test, cleaned up in tearDown().
     *
     * @var int[]
     */
    private array $created_video_ids = [];

    /**
     * Delete every row created by the test.
     *
     * @throws RuntimeException If a cleanup query template is malformed (a bug in this method).
     */
    protected function tearDown(): void
    {
        foreach ($this->created_actor_ids as $actor_id) {
            global $wpdb;
            /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup against dedicated custom tables.
            $wpdb->delete($wpdb->prefix . 'tube_video_actors', ['actor_id' => $actor_id], ['%d']);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup against dedicated custom tables.
            $wpdb->delete($wpdb->prefix . 'tube_actors', ['id' => $actor_id], ['%d']);
        }

        foreach ($this->created_studio_ids as $studio_id) {
            global $wpdb;
            /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup against dedicated custom tables.
            $wpdb->delete($wpdb->prefix . 'tube_video_studios', ['studio_id' => $studio_id], ['%d']);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup against dedicated custom tables.
            $wpdb->delete($wpdb->prefix . 'tube_studios', ['id' => $studio_id], ['%d']);
        }

        foreach ($this->created_video_ids as $video_id) {
            wp_delete_post($video_id, true);
        }

        $this->created_actor_ids  = [];
        $this->created_studio_ids = [];
        $this->created_video_ids  = [];
    }

    /**
     * Find_by_slug() and find() both resolve a real seeded actor row.
     */
    public function test_find_by_slug_and_find_resolve_a_real_actor(): void
    {
        $actor_id = $this->create_actor('Jane Doe', 'A biography.');

        $repository = Tube_Core_Plugin::instance()->actor_repository();

        $by_slug = $repository->find_by_slug('jane-doe-' . $actor_id);
        $by_id   = $repository->find($actor_id);

        self::assertNotNull($by_slug);
        self::assertSame('Jane Doe', $by_slug->name);
        self::assertNotNull($by_id);
        self::assertSame($by_slug->id, $by_id->id);
    }

    /**
     * An unknown actor slug resolves to null, not an error.
     */
    public function test_find_by_slug_returns_null_for_unknown_slug(): void
    {
        $repository = Tube_Core_Plugin::instance()->actor_repository();

        self::assertNull($repository->find_by_slug('no-such-actor-slug'));
    }

    /**
     * Actor_ids_for_video()/count_videos_for_actor() reflect real wp_tube_video_actors rows.
     */
    public function test_actor_ids_for_video_and_count_reflect_real_assignments(): void
    {
        $actor_id = $this->create_actor('Actor With Videos', null);
        $video_id = $this->create_video();

        $this->assign_actor_to_video($actor_id, $video_id);

        $repository = Tube_Core_Plugin::instance()->actor_repository();

        self::assertSame([$actor_id], $repository->actor_ids_for_video($video_id));
        self::assertSame(1, $repository->count_videos_for_actor($actor_id));
    }

    /**
     * Find_by_slug() resolves a real seeded studio row.
     */
    public function test_find_by_slug_resolves_a_real_studio(): void
    {
        $studio_id = $this->create_studio('Acme Studio', 'https://example.com');

        $repository = Tube_Core_Plugin::instance()->studio_repository();

        $studio = $repository->find_by_slug('acme-studio-' . $studio_id);

        self::assertNotNull($studio);
        self::assertSame('Acme Studio', $studio->name);
        self::assertSame('https://example.com', $studio->website_url);
    }

    /**
     * A real request to /actor/{slug}/ resolves the correct actor via
     * TermArchiveRouting's real `add_rewrite_rule()`/`template_include`
     * wiring — not just the repository lookup in isolation.
     */
    public function test_actor_archive_request_resolves_the_correct_actor(): void
    {
        $actor_id = $this->create_actor('Routing Test Actor', null);
        $slug     = 'routing-test-actor-' . $actor_id;

        global $wp_query;
        /** @var WP_Query $wp_query */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $this->go_to_query_var('tube_actor', $slug);

        $resolved = tube_core_get_current_actor();

        self::assertNotNull($resolved);
        self::assertSame($actor_id, $resolved->id);
        self::assertFalse($wp_query->is_404());
    }

    /**
     * An unknown actor slug produces a real 404, not a fatal or an empty page.
     */
    public function test_unknown_actor_slug_produces_a_404(): void
    {
        global $wp_query;
        /** @var WP_Query $wp_query */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $this->go_to_query_var('tube_actor', 'definitely-does-not-exist-actor');

        self::assertTrue($wp_query->is_404());
    }

    /**
     * Simulate a real `/actor/{slug}/`-style request by setting the query
     * var directly and re-running `template_include`'s filter chain — the
     * same effect the real rewrite rule has once matched, without needing
     * a full HTTP round-trip inside the test process.
     *
     * @param string $query_var The query var TermArchiveRouting listens on.
     * @param string $value     The slug value.
     */
    private function go_to_query_var(string $query_var, string $value): void
    {
        global $wp_query;
        /** @var WP_Query $wp_query */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        // Reset any 404 state a prior test in this process may have left behind.
        $wp_query->is_404 = false;

        set_query_var($query_var, $value);

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- re-triggering WordPress core's own template_include filter to simulate real request routing, not defining a new hook.
        apply_filters('template_include', '');
    }

    /**
     * Create a real actor row, tracked for teardown.
     *
     * @param string      $name The actor's name.
     * @param string|null $bio  The actor's bio, if any.
     */
    private function create_actor(string $name, ?string $bio): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $now = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test seeding against a dedicated custom table with no write API yet (Phase 10).
        $wpdb->insert(
            $wpdb->prefix . 'tube_actors',
            [
                'name'       => $name,
                'slug'       => 'placeholder',
                'bio'        => $bio,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );

        $actor_id = (int) $wpdb->insert_id;

        // The real slug embeds the row ID (set after insert) so parallel test runs never collide.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see comment above.
        $wpdb->update(
            $wpdb->prefix . 'tube_actors',
            ['slug' => sanitize_title($name) . '-' . $actor_id],
            ['id' => $actor_id],
            ['%s'],
            ['%d']
        );

        $this->created_actor_ids[] = $actor_id;

        return $actor_id;
    }

    /**
     * Create a real studio row, tracked for teardown.
     *
     * @param string      $name        The studio's name.
     * @param string|null $website_url The studio's website, if any.
     */
    private function create_studio(string $name, ?string $website_url): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $now = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test seeding against a dedicated custom table with no write API yet (Phase 10).
        $wpdb->insert(
            $wpdb->prefix . 'tube_studios',
            [
                'name'        => $name,
                'slug'        => 'placeholder',
                'website_url' => $website_url,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );

        $studio_id = (int) $wpdb->insert_id;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see comment above.
        $wpdb->update(
            $wpdb->prefix . 'tube_studios',
            ['slug' => sanitize_title($name) . '-' . $studio_id],
            ['id' => $studio_id],
            ['%s'],
            ['%d']
        );

        $this->created_studio_ids[] = $studio_id;

        return $studio_id;
    }

    /**
     * Create a real published video post, tracked for teardown.
     */
    private function create_video(): int
    {
        $video_id = wp_insert_post(
            [
                'post_type'   => 'video',
                'post_title'  => 'Actor/Studio Integration Test Video',
                'post_status' => 'publish',
            ],
            true
        );

        self::assertIsInt($video_id);
        $this->created_video_ids[] = $video_id;

        return $video_id;
    }

    /**
     * Insert a real wp_tube_video_actors relationship row.
     *
     * @param int $actor_id The actor's row ID.
     * @param int $video_id The video post ID.
     */
    private function assign_actor_to_video(int $actor_id, int $video_id): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test seeding against a dedicated custom table with no write API yet (Phase 10).
        $wpdb->insert(
            $wpdb->prefix . 'tube_video_actors',
            [
                'video_id' => $video_id,
                'actor_id' => $actor_id,
            ],
            ['%d', '%d']
        );
    }
}
