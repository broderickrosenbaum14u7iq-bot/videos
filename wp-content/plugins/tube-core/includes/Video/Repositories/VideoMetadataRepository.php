<?php
/**
 * Data access for wp_tube_video_metadata (VideoMetadataRepositoryInterface).
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Video\Repositories;

use Tube_Core\Video\CfStreamStatus;

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
}
