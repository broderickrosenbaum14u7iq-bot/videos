<?php
/**
 * Test fixture: an in-memory VideoMetadataRepositoryInterface.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Video\Fixtures;

use Tube_Core\Video\CfStreamStatus;
use Tube_Core\Video\Repositories\VideoMetadataRepositoryInterface;

/**
 * An in-memory VideoMetadataRepositoryInterface — no database. Stateful
 * (not just a call recorder): {@see self::find_video_id_by_stream_uid()}
 * and {@see self::status_for()} reflect what {@see self::create()}/
 * {@see self::update_status()} actually wrote, which is what lets
 * `StreamStatusUpdaterTest` exercise real compare-old-vs-new-status
 * behavior against this fake.
 */
final class InMemoryVideoMetadataRepository implements VideoMetadataRepositoryInterface
{
    /**
     * Video ID, keyed by Cloudflare Stream UID.
     *
     * @var array<string, int>
     */
    private array $video_id_by_uid = [];

    /**
     * Current status, keyed by video ID.
     *
     * @var array<int, CfStreamStatus>
     */
    private array $status_by_video_id = [];

    /**
     * Every create() call this fake received, in order.
     *
     * @var list<array{video_id: int, cf_stream_uid: string, status: CfStreamStatus}>
     */
    public array $create_calls = [];

    /**
     * Every update_status() call this fake received, in order.
     *
     * @var list<array{video_id: int, status: CfStreamStatus, duration_seconds: int|null}>
     */
    public array $update_status_calls = [];

    /**
     * Seed existing state, as if a prior create()/update_status() had
     * already happened — for test setup, not part of the interface.
     *
     * @param string         $cf_stream_uid The Cloudflare Stream UID.
     * @param int            $video_id      The video post ID.
     * @param CfStreamStatus $status        The status to seed.
     */
    public function seed(string $cf_stream_uid, int $video_id, CfStreamStatus $status): void
    {
        $this->video_id_by_uid[ $cf_stream_uid ] = $video_id;
        $this->status_by_video_id[ $video_id ]   = $status;
    }

    /**
     * {@inheritDoc}
     *
     * @param int            $video_id      The video post ID.
     * @param string         $cf_stream_uid The Cloudflare Stream UID.
     * @param CfStreamStatus $status        The initial encoding status.
     */
    public function create(int $video_id, string $cf_stream_uid, CfStreamStatus $status): void
    {
        $this->create_calls[] = [
            'video_id'      => $video_id,
            'cf_stream_uid' => $cf_stream_uid,
            'status'        => $status,
        ];

        $this->video_id_by_uid[ $cf_stream_uid ] = $video_id;
        $this->status_by_video_id[ $video_id ]   = $status;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $cf_stream_uid The Cloudflare Stream UID to look up.
     */
    public function find_video_id_by_stream_uid(string $cf_stream_uid): ?int
    {
        return $this->video_id_by_uid[ $cf_stream_uid ] ?? null;
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video post ID.
     */
    public function status_for(int $video_id): ?CfStreamStatus
    {
        return $this->status_by_video_id[ $video_id ] ?? null;
    }

    /**
     * {@inheritDoc}
     *
     * @param int            $video_id         The video post ID.
     * @param CfStreamStatus $status           The new encoding status.
     * @param int|null       $duration_seconds The video's duration, if known.
     */
    public function update_status(int $video_id, CfStreamStatus $status, ?int $duration_seconds): void
    {
        $this->update_status_calls[] = [
            'video_id'         => $video_id,
            'status'           => $status,
            'duration_seconds' => $duration_seconds,
        ];

        $this->status_by_video_id[ $video_id ] = $status;
    }
}
