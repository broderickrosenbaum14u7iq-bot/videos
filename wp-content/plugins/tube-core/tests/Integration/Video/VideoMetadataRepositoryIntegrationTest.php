<?php
/**
 * Integration tests for VideoMetadataRepository::find(), against a real database.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Integration\Video;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tube_Core\Video\CfStreamStatus;
use Tube_Core\Video\Repositories\VideoMetadataRepository;
use Tube_Core\Video\VideoSource;

/**
 * `find()` is Phase 6's read path for tube-player — this confirms it
 * round-trips every stored column correctly against the real
 * wp_tube_video_metadata table, which the existing unit test fakes
 * cannot verify (they never touch real SQL).
 */
final class VideoMetadataRepositoryIntegrationTest extends TestCase
{
    /**
     * The repository under test.
     *
     * @var VideoMetadataRepository
     */
    private VideoMetadataRepository $repository;

    /**
     * A real video post ID created for the test.
     *
     * @var int
     */
    private int $video_id;

    /**
     * Build a real repository and a real video post for each test.
     */
    protected function setUp(): void
    {
        $this->repository = new VideoMetadataRepository();

        $video_id = wp_insert_post(
            [
                'post_type'   => 'video',
                'post_title'  => 'VideoMetadataRepository::find() Integration Test Video',
                'post_status' => 'draft',
            ],
            true
        );

        self::assertIsInt($video_id);
        $this->video_id = $video_id;
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test cleanup against a dedicated custom table; $sql *is* $wpdb->prepare()'d above.
        $wpdb->query($sql);

        wp_delete_post($this->video_id, true);
    }

    /**
     * `find()` returns null for a video with no metadata row.
     */
    public function test_find_returns_null_for_a_video_with_no_metadata_row(): void
    {
        self::assertNull($this->repository->find($this->video_id));
    }

    /**
     * `find()` round-trips every stored column, including the ones
     * create()/update_status() never set directly (thumbnail offset,
     * image overrides), which only a real SELECT against the actual
     * table (not a repository fake) can prove.
     */
    public function test_find_round_trips_every_stored_column(): void
    {
        $cf_stream_uid = 'uid-' . uniqid('', true);

        $this->repository->create($this->video_id, $cf_stream_uid, CfStreamStatus::Pending);
        $this->repository->update_status($this->video_id, CfStreamStatus::Ready, 125);

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup writing columns no repository method exposes yet (poster/OG image overrides, thumbnail offset).
        $wpdb->update(
            $wpdb->prefix . 'tube_video_metadata',
            [
                'thumbnail_time_seconds' => 12,
                'poster_image_id'        => 501,
                'og_image_id'            => 502,
            ],
            ['video_id' => $this->video_id],
            ['%d', '%d', '%d'],
            ['%d']
        );

        $metadata = $this->repository->find($this->video_id);

        self::assertNotNull($metadata);
        self::assertSame($this->video_id, $metadata->video_id);
        self::assertSame($cf_stream_uid, $metadata->cf_stream_uid);
        self::assertSame(CfStreamStatus::Ready, $metadata->cf_status);
        self::assertSame(125, $metadata->duration_seconds);
        self::assertSame(12, $metadata->thumbnail_time_seconds);
        self::assertSame(501, $metadata->poster_image_id);
        self::assertSame(502, $metadata->og_image_id);
    }

    /**
     * Phase 11: after find_many() primes the cache, a subsequent find()
     * for the same ID issues zero additional queries.
     */
    public function test_find_after_find_many_issues_no_additional_query(): void
    {
        $this->repository->create($this->video_id, 'uid-' . uniqid('', true), CfStreamStatus::Ready);

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $this->repository->find_many([$this->video_id]);

        $queries_before = $wpdb->num_queries;
        $metadata       = $this->repository->find($this->video_id);
        $queries_after  = $wpdb->num_queries;

        self::assertNotNull($metadata);
        self::assertSame($queries_before, $queries_after, 'find() after find_many() should not issue a new query.');
    }

    /**
     * Phase 11: find_many() also caches "no row for this ID" — a
     * subsequent find() for a video genuinely absent from the table
     * issues zero additional queries either.
     */
    public function test_find_after_find_many_caches_a_negative_result_too(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        // $this->video_id has no metadata row in this test.
        $this->repository->find_many([$this->video_id]);

        $queries_before = $wpdb->num_queries;
        $metadata       = $this->repository->find($this->video_id);
        $queries_after  = $wpdb->num_queries;

        self::assertNull($metadata);
        self::assertSame($queries_before, $queries_after, 'find() after find_many() should not re-query.');
    }

    /**
     * Phase 11: a second find_many() call only queries for IDs not
     * already cached by the first call.
     */
    public function test_find_many_does_not_requery_already_cached_ids(): void
    {
        $second_video_id = wp_insert_post(
            [
                'post_type'   => 'video',
                'post_title'  => 'VideoMetadataRepository::find_many() second Test Video',
                'post_status' => 'draft',
            ],
            true
        );
        self::assertIsInt($second_video_id);

        try {
            $this->repository->create($this->video_id, 'uid-a-' . uniqid('', true), CfStreamStatus::Ready);
            $this->repository->create($second_video_id, 'uid-b-' . uniqid('', true), CfStreamStatus::Ready);

            global $wpdb;
            /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

            $this->repository->find_many([$this->video_id]);

            $queries_before = $wpdb->num_queries;
            $result         = $this->repository->find_many([$this->video_id, $second_video_id]);
            $queries_after  = $wpdb->num_queries;

            self::assertCount(2, $result);
            self::assertGreaterThan(
                $queries_before,
                $queries_after,
                'A find_many() call with a genuinely-new ID should still issue a query.'
            );
        } finally {
            global $wpdb;
            /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

            $wpdb->delete($wpdb->prefix . 'tube_video_metadata', ['video_id' => $second_video_id], ['%d']);
            wp_delete_post($second_video_id, true);
        }
    }

    /**
     * Phase 11 regression test: a find() that caches "no row yet" must
     * not shadow a create() for that same video within the same request.
     * Caught by this phase's own Implementation Review (test suite
     * re-run) as a real bug — the cache was originally invalidated
     * nowhere, so tube-player's/tube-seo's own integration tests (which
     * call find() before create() via a shared repository instance)
     * started failing once caching was added.
     */
    public function test_find_after_create_reflects_the_new_row_even_when_find_cached_no_row_first(): void
    {
        self::assertNull($this->repository->find($this->video_id), 'Sanity check: no row yet, so this caches null.');

        $this->repository->create($this->video_id, 'uid-' . uniqid('', true), CfStreamStatus::Ready);

        self::assertNotNull(
            $this->repository->find($this->video_id),
            'find() after create() must see the new row, not the null cached before create() ran.'
        );
    }

    /**
     * Phase 11 regression test: the same shadowing risk for
     * update_status()/update_images()/update_thumbnail_time() — a find()
     * that cached the pre-update row must not shadow the write.
     */
    public function test_find_after_update_status_reflects_the_change(): void
    {
        $this->repository->create($this->video_id, 'uid-' . uniqid('', true), CfStreamStatus::Pending);

        self::assertSame(CfStreamStatus::Pending, $this->repository->find($this->video_id)?->cf_status);

        $this->repository->update_status($this->video_id, CfStreamStatus::Ready, 90);

        self::assertSame(
            CfStreamStatus::Ready,
            $this->repository->find($this->video_id)?->cf_status,
            'find() after update_status() must see the new status, not the one cached before the update.'
        );
    }

    /**
     * Phase (ADR-0001) regression test: update_stream_uid(), added for
     * tube-admin's manually-editable Stream UID field, persists the new
     * UID and invalidates the cache, and find_video_id_by_stream_uid()
     * resolves the new UID afterward.
     */
    public function test_update_stream_uid_persists_the_new_uid_and_invalidates_the_cache(): void
    {
        $original_uid = 'uid-original-' . uniqid('', true);
        $new_uid      = 'uid-new-' . uniqid('', true);

        $this->repository->create($this->video_id, $original_uid, CfStreamStatus::Pending);

        self::assertSame($original_uid, $this->repository->find($this->video_id)?->cf_stream_uid);

        $this->repository->update_stream_uid($this->video_id, $new_uid);

        self::assertSame(
            $new_uid,
            $this->repository->find($this->video_id)?->cf_stream_uid,
            'find() after update_stream_uid() must see the new UID, not the one cached before the update.'
        );
        self::assertSame($this->video_id, $this->repository->find_video_id_by_stream_uid($new_uid));
        self::assertNull(
            $this->repository->find_video_id_by_stream_uid($original_uid),
            'The old UID must no longer resolve to any video once it has been replaced.'
        );
    }

    /**
     * `all_stream_uids()` — the batch-resync read path
     * `Tube_Core\CLI\StreamCommand` walks the whole catalog with — surfaces
     * a real video's row somewhere across a full paginated walk, with its
     * exact stored UID. Doesn't assert an exact/exhaustive listing (this
     * table holds real rows from other tests/environments too, the same
     * shared-database-pollution risk `SitemapGeneratorIntegrationTest`
     * already had to account for) — walking with a small page size and
     * collecting every row proves pagination itself works (no row
     * skipped/duplicated across pages) without depending on the table
     * being otherwise empty.
     */
    public function test_all_stream_uids_surfaces_a_real_row_across_a_paginated_walk(): void
    {
        $cf_stream_uid = 'uid-all-stream-uids-' . uniqid('', true);
        $this->repository->create($this->video_id, $cf_stream_uid, CfStreamStatus::Pending);

        $found  = null;
        $offset = 0;
        $limit  = 3;
        $seen   = [];

        do {
            $page      = $this->repository->all_stream_uids($limit, $offset);
            $page_size = count($page);

            foreach ($page as $row) {
                self::assertArrayNotHasKey(
                    $row['video_id'],
                    $seen,
                    'all_stream_uids() must not return the same video_id on two different pages.'
                );
                $seen[ $row['video_id'] ] = true;

                if ($row['video_id'] === $this->video_id) {
                    $found = $row;
                }
            }

            $offset += $limit;
        } while ($page_size === $limit);

        self::assertNotNull($found, 'The created video must appear somewhere in the paginated walk.');
        self::assertSame($cf_stream_uid, $found['cf_stream_uid']);
    }

    /**
     * A video with no metadata row at all defaults to nothing stored yet
     * — this is the "never created" state, distinct from {@see self::test_find_round_trips_an_r2_video()}'s
     * "created as R2" state. `create()`'s own CloudflareStream-defaulting
     * behavior (the backward-compatibility contract every pre-existing
     * Stream video relies on) is already covered by the existing
     * Stream-focused tests above, which never explicitly assert
     * `source`; this confirms it directly.
     */
    public function test_create_defaults_source_to_cloudflare_stream(): void
    {
        $this->repository->create($this->video_id, 'uid-' . uniqid('', true), CfStreamStatus::Ready);

        $metadata = $this->repository->find($this->video_id);

        self::assertNotNull($metadata);
        self::assertSame(VideoSource::CloudflareStream, $metadata->source);
        self::assertNull($metadata->r2_object_key);
    }

    /**
     * `create_r2()` round-trips every R2-specific field, and never sets a
     * Cloudflare Stream UID.
     */
    public function test_create_r2_round_trips_the_stored_object_key(): void
    {
        $object_key = 'videos/r2-test-' . uniqid('', true) . '.mp4';

        $this->repository->create_r2($this->video_id, $object_key, CfStreamStatus::Ready);

        $metadata = $this->repository->find($this->video_id);

        self::assertNotNull($metadata);
        self::assertSame(VideoSource::R2Mp4, $metadata->source);
        self::assertSame($object_key, $metadata->r2_object_key);
        self::assertNull($metadata->cf_stream_uid);
        self::assertSame(CfStreamStatus::Ready, $metadata->cf_status);
    }

    /**
     * `find_video_id_by_r2_object_key()` resolves a real stored key, and
     * returns null for one that was never stored — the R2 counterpart to
     * the existing Stream UID lookup tests.
     */
    public function test_find_video_id_by_r2_object_key_resolves_a_real_key(): void
    {
        $object_key = 'videos/r2-lookup-' . uniqid('', true) . '.mp4';

        self::assertNull($this->repository->find_video_id_by_r2_object_key($object_key));

        $this->repository->create_r2($this->video_id, $object_key, CfStreamStatus::Ready);

        self::assertSame($this->video_id, $this->repository->find_video_id_by_r2_object_key($object_key));
    }

    /**
     * `update_r2_object_key()` changes the stored key for a video that
     * already has R2 metadata, and the old key no longer resolves to it.
     */
    public function test_update_r2_object_key_replaces_the_stored_key(): void
    {
        $original = 'videos/r2-original-' . uniqid('', true) . '.mp4';
        $updated  = 'videos/r2-updated-' . uniqid('', true) . '.mp4';

        $this->repository->create_r2($this->video_id, $original, CfStreamStatus::Ready);
        $this->repository->update_r2_object_key($this->video_id, $updated);

        self::assertSame($updated, $this->repository->find($this->video_id)?->r2_object_key);
        self::assertNull($this->repository->find_video_id_by_r2_object_key($original));
        self::assertSame($this->video_id, $this->repository->find_video_id_by_r2_object_key($updated));
    }
}
