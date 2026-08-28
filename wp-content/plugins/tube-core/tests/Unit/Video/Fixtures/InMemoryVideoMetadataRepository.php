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
use Tube_Core\Video\VideoMetadata;

/**
 * An in-memory VideoMetadataRepositoryInterface — no database. Stateful
 * (not just a call recorder): {@see self::find_video_id_by_stream_uid()},
 * {@see self::status_for()}, and {@see self::find()}'s duration reflect
 * what {@see self::create()}/{@see self::update_status()} actually wrote,
 * which is what lets `StreamStatusUpdaterTest` exercise real
 * compare-old-vs-new-status-and-duration behavior against this fake.
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
     * Current duration, keyed by video ID — a key's mere presence (checked
     * via array_key_exists(), not isset()) means "this video has a known
     * duration," since null is a legitimate "not yet known" value distinct
     * from "never written."
     *
     * @var array<int, int|null>
     */
    private array $duration_by_video_id = [];

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
     * Poster/OG image overrides, keyed by video ID.
     *
     * @var array<int, array{poster_image_id: int|null, og_image_id: int|null}>
     */
    private array $images_by_video_id = [];

    /**
     * Thumbnail source-frame offsets, keyed by video ID.
     *
     * @var array<int, int>
     */
    private array $thumbnail_time_by_video_id = [];

    /**
     * Seed existing state, as if a prior create()/update_status() had
     * already happened — for test setup, not part of the interface.
     *
     * @param string         $cf_stream_uid    The Cloudflare Stream UID.
     * @param int            $video_id         The video post ID.
     * @param CfStreamStatus $status           The status to seed.
     * @param int|null       $duration_seconds The duration to seed, if any (defaults to not-yet-known).
     */
    public function seed(
        string $cf_stream_uid,
        int $video_id,
        CfStreamStatus $status,
        ?int $duration_seconds = null
    ): void {
        $this->video_id_by_uid[ $cf_stream_uid ] = $video_id;
        $this->status_by_video_id[ $video_id ]   = $status;
        $this->duration_by_video_id[ $video_id ] = $duration_seconds;
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
        $this->duration_by_video_id[ $video_id ] = null;
    }

    /**
     * {@inheritDoc}
     *
     * Implemented from the same state maps `create()`/`update_status()`
     * already track, defaulting the fields this fixture doesn't track
     * (thumbnail offset, image overrides) to satisfy the interface
     * honestly rather than leaving them unimplemented. Duration IS
     * tracked (`self::$duration_by_video_id`) — `StreamStatusUpdaterTest`
     * needs a real "what duration does this video currently have" answer
     * to exercise its compare-old-vs-new-duration decision logic against
     * this fake.
     *
     * @param int $video_id The video post ID.
     */
    public function find(int $video_id): ?VideoMetadata
    {
        $status = $this->status_by_video_id[ $video_id ] ?? null;

        if (null === $status) {
            return null;
        }

        $cf_stream_uid = array_search($video_id, $this->video_id_by_uid, true);
        $images        = $this->images_by_video_id[ $video_id ] ?? [
            'poster_image_id' => null,
            'og_image_id'     => null,
        ];

        return new VideoMetadata(
            $video_id,
            is_string($cf_stream_uid) ? $cf_stream_uid : '',
            $status,
            $this->duration_by_video_id[ $video_id ] ?? null,
            $this->thumbnail_time_by_video_id[ $video_id ] ?? 0,
            $images['poster_image_id'],
            $images['og_image_id']
        );
    }

    /**
     * {@inheritDoc}
     *
     * Not exercised by any current consumer of this fixture — see {@see self::find()}'s docblock.
     *
     * @param int[] $video_ids The video post IDs to fetch.
     *
     * @return array<int, VideoMetadata>
     */
    public function find_many(array $video_ids): array
    {
        $result = [];

        foreach ($video_ids as $video_id) {
            $metadata = $this->find($video_id);

            if (null !== $metadata) {
                $result[ $video_id ] = $metadata;
            }
        }

        return $result;
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

        // Matches the real repository's own "leave unchanged if null"
        // contract (VideoMetadataRepository::update_status()) — only a
        // non-null $duration_seconds overwrites what's already stored.
        if (null !== $duration_seconds) {
            $this->duration_by_video_id[ $video_id ] = $duration_seconds;
        }
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
        $this->images_by_video_id[ $video_id ] = [
            'poster_image_id' => $poster_image_id,
            'og_image_id'     => $og_image_id,
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id               The video post ID.
     * @param int $thumbnail_time_seconds The offset, in seconds, to extract the default thumbnail from.
     */
    public function update_thumbnail_time(int $video_id, int $thumbnail_time_seconds): void
    {
        $this->thumbnail_time_by_video_id[ $video_id ] = $thumbnail_time_seconds;
    }

    /**
     * {@inheritDoc}
     *
     * @param int    $video_id      The video post ID.
     * @param string $cf_stream_uid The new Cloudflare Stream UID.
     */
    public function update_stream_uid(int $video_id, string $cf_stream_uid): void
    {
        // Remove any prior UID mapping for this video before recording
        // the new one, so a UID isn't left pointing at a video that no
        // longer has it — the same state-consistency
        // create()/update_status() already maintain in this fixture.
        $previous_uid = array_search($video_id, $this->video_id_by_uid, true);

        if (is_string($previous_uid)) {
            unset($this->video_id_by_uid[ $previous_uid ]);
        }

        $this->video_id_by_uid[ $cf_stream_uid ] = $video_id;
    }

    /**
     * {@inheritDoc}
     *
     * Not exercised by any current consumer of this fixture (`Tube_Core\CLI\StreamCommand`
     * is WordPress/WP-CLI-coupled and verified live, not unit-tested) —
     * implemented for real (not stubbed) from `self::$video_id_by_uid`,
     * ordered by video_id, so this fixture stays honest about what it
     * actually holds if a future consumer does need it.
     *
     * @param int $limit  How many rows to return.
     * @param int $offset How many rows to skip.
     *
     * @return list<array{video_id: int, cf_stream_uid: string}>
     */
    public function all_stream_uids(int $limit, int $offset): array
    {
        $by_video_id = array_flip($this->video_id_by_uid);
        ksort($by_video_id);

        $page = array_slice($by_video_id, $offset, $limit, true);

        $result = [];

        foreach ($page as $video_id => $cf_stream_uid) {
            $result[] = [
                'video_id'      => $video_id,
                'cf_stream_uid' => $cf_stream_uid,
            ];
        }

        return $result;
    }
}
