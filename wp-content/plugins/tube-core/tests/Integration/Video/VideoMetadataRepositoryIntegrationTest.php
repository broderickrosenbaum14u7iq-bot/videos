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
}
