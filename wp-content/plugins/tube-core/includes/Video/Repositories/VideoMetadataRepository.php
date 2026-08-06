<?php
/**
 * Data access for wp_tube_video_metadata (VideoMetadataRepositoryInterface).
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Video\Repositories;

use RuntimeException;
use Tube_Core\Video\CfStreamStatus;
use Tube_Core\Video\VideoMetadata;

/**
 * Data access for wp_tube_video_metadata (VideoMetadataRepositoryInterface).
 *
 * Direct $wpdb access is the same documented, intentional exception
 * every dedicated-table repository in this project uses (ARCHITECTURE.md
 * §2.5/§11) — no WP_Query/WP_Meta_Query equivalent exists for this
 * table. Every operation here is single-row, so it uses $wpdb's own
 * insert()/update()/get_var() helpers (already internally parameterized)
 * rather than hand-written SQL — the same style
 * `Tube_Core\Database\SchemaVersionStore` already established in Phase 1.
 */
final class VideoMetadataRepository implements VideoMetadataRepositoryInterface
{
    /**
     * {@inheritDoc}
     *
     * @param int            $video_id      The video post ID.
     * @param string         $cf_stream_uid The Cloudflare Stream UID.
     * @param CfStreamStatus $status        The initial encoding status.
     */
    public function create(int $video_id, string $cf_stream_uid, CfStreamStatus $status): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $now = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->insert(
            $wpdb->prefix . 'tube_video_metadata',
            [
                'video_id'      => $video_id,
                'cf_stream_uid' => $cf_stream_uid,
                'cf_status'     => $status->value,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video post ID.
     */
    public function find(int $video_id): ?VideoMetadata
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT video_id, cf_stream_uid, cf_status, duration_seconds, thumbnail_time_seconds,'
                . ' poster_image_id, og_image_id FROM %i WHERE video_id = %d',
                $wpdb->prefix . 'tube_video_metadata',
                $video_id
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return null;
        }

        /** @var array{video_id: string, cf_stream_uid: string, cf_status: string, duration_seconds: string|null, thumbnail_time_seconds: string, poster_image_id: string|null, og_image_id: string|null} $row */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        return self::hydrate($row);
    }

    /**
     * {@inheritDoc}
     *
     * @param int[] $video_ids The video post IDs to fetch.
     *
     * @return array<int, VideoMetadata>
     *
     * @throws RuntimeException If the query template is malformed (a bug in this method, not in any argument).
     */
    public function find_many(array $video_ids): array
    {
        if ([] === $video_ids) {
            return [];
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $placeholders = implode(', ', array_fill(0, count($video_ids), '%d'));

        $sql = $wpdb->prepare(
            'SELECT video_id, cf_stream_uid, cf_status, duration_seconds, thumbnail_time_seconds,'
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $placeholders is a fixed-shape string of literal "%d" tokens (one per element of $video_ids), never external input, and every actual value is still a %i/%d-bound argument below.
                . " poster_image_id, og_image_id FROM %i WHERE video_id IN ({$placeholders})",
            array_merge([$wpdb->prefix . 'tube_video_metadata'], $video_ids)
        );

        if (null === $sql) {
            throw new RuntimeException(
                'wpdb::prepare() returned null for the find_many() query in ' . self::class . '.'
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above.
        $rows = $wpdb->get_results($sql, ARRAY_A);

        /** @var array<int, array{video_id: string, cf_stream_uid: string, cf_status: string, duration_seconds: string|null, thumbnail_time_seconds: string, poster_image_id: string|null, og_image_id: string|null}> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $rows = (array) $rows;

        $result = [];

        foreach ($rows as $row) {
            $metadata                      = self::hydrate($row);
            $result[ $metadata->video_id ] = $metadata;
        }

        return $result;
    }

    /**
     * Turn one raw $wpdb row into a VideoMetadata.
     *
     * @param array<string, string|null> $row One raw $wpdb row.
     *
     * @phpstan-param array{
     *     video_id: string,
     *     cf_stream_uid: string,
     *     cf_status: string,
     *     duration_seconds: string|null,
     *     thumbnail_time_seconds: string,
     *     poster_image_id: string|null,
     *     og_image_id: string|null
     * } $row
     */
    private static function hydrate(array $row): VideoMetadata
    {
        return new VideoMetadata(
            (int) $row['video_id'],
            $row['cf_stream_uid'],
            CfStreamStatus::from($row['cf_status']),
            null === $row['duration_seconds'] ? null : (int) $row['duration_seconds'],
            (int) $row['thumbnail_time_seconds'],
            null === $row['poster_image_id'] ? null : (int) $row['poster_image_id'],
            null === $row['og_image_id'] ? null : (int) $row['og_image_id']
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param string $cf_stream_uid The Cloudflare Stream UID to look up.
     */
    public function find_video_id_by_stream_uid(string $cf_stream_uid): ?int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $video_id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT video_id FROM %i WHERE cf_stream_uid = %s',
                $wpdb->prefix . 'tube_video_metadata',
                $cf_stream_uid
            )
        );

        return null === $video_id ? null : (int) $video_id;
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video post ID.
     */
    public function status_for(int $video_id): ?CfStreamStatus
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $status = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT cf_status FROM %i WHERE video_id = %d',
                $wpdb->prefix . 'tube_video_metadata',
                $video_id
            )
        );

        return null === $status ? null : CfStreamStatus::from($status);
    }

    /**
     * {@inheritDoc}
     *
     * @param int            $video_id         The video post ID.
     * @param CfStreamStatus $status           The new encoding status.
     * @param int|null       $duration_seconds The video's duration, if known; left unchanged if null.
     */
    public function update_status(int $video_id, CfStreamStatus $status, ?int $duration_seconds): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_video_metadata';
        $now   = current_time('mysql', true);

        $data    = [
            'cf_status'  => $status->value,
            'updated_at' => $now,
        ];
        $formats = ['%s', '%s'];

        if (null !== $duration_seconds) {
            $data['duration_seconds'] = $duration_seconds;
            $formats[]                = '%d';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->update($table, $data, ['video_id' => $video_id], $formats, ['%d']);
    }

    /**
     * {@inheritDoc}
     *
     * @param int      $video_id        The video post ID.
     * @param int|null $poster_image_id The Cloudflare Images ID to use as the poster override, or null to clear it.
     * @param int|null $og_image_id     The Cloudflare Images ID to use as the OG-image override, or null to clear it.
     */
    public function update_images(int $video_id, ?int $poster_image_id, ?int $og_image_id): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->update(
            $wpdb->prefix . 'tube_video_metadata',
            [
                'poster_image_id' => $poster_image_id,
                'og_image_id'     => $og_image_id,
                'updated_at'      => current_time('mysql', true),
            ],
            ['video_id' => $video_id],
            ['%d', '%d', '%s'],
            ['%d']
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id               The video post ID.
     * @param int $thumbnail_time_seconds The offset, in seconds, to extract the default thumbnail from.
     */
    public function update_thumbnail_time(int $video_id, int $thumbnail_time_seconds): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->update(
            $wpdb->prefix . 'tube_video_metadata',
            [
                'thumbnail_time_seconds' => $thumbnail_time_seconds,
                'updated_at'             => current_time('mysql', true),
            ],
            ['video_id' => $video_id],
            ['%d', '%s'],
            ['%d']
        );
    }
}
