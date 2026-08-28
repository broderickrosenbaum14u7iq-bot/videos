<?php
/**
 * `wp tube-core stream:resync`.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\CLI;

use Tube_Core\Stream\StreamMetadataSyncer;
use Tube_Core\Video\Repositories\VideoMetadataRepositoryInterface;
use WP_CLI;

/**
 * `wp tube-core stream:resync` — the safe, explicit way to backfill real
 * Cloudflare Stream status/duration for videos after
 * `TUBE_CORE_CLOUDFLARE_STREAM_ACCOUNT_ID`/`API_TOKEN` are configured
 * (they aren't required for playback — see `StreamMetadataSyncer`'s own
 * docblock for the credentials this command needs).
 *
 * Every video already gets a live sync attempt the moment its Stream UID
 * is first set or changed (`Tube_Admin\Video\StreamUidMetaBox::save()`).
 * This command exists for videos that predate that — imported, or
 * manually entered, before credentials existed to sync against — and for
 * re-running a sync on demand (a video stuck `Processing` because
 * Cloudflare hadn't finished encoding yet, or an admin wants to confirm
 * the current Cloudflare-side duration matches what's stored).
 *
 * Walks the whole catalog in batches (`VideoMetadataRepositoryInterface::all_stream_uids()`),
 * the same `$limit`/`$offset` do-while-until-a-short-page pagination
 * shape `Tube_Search\CLI\IndexCommand::rebuild()` already established,
 * rather than loading every row into memory at once (ARCHITECTURE.md
 * §10's 500,000+-video scale target).
 *
 * Every individual sync goes through the exact same
 * `StreamMetadataSyncer::sync()` a manually-entered UID's own live sync
 * attempt uses — same "never corrupt existing data on failure" contract,
 * same `EventCatalog::VIDEO_STREAM_STATUS_CHANGED` dispatch on a real
 * change (which keeps `wp_tube_search_index`/tube-cache's homepage/
 * category/search-card caches in sync automatically — see
 * `StreamStatusUpdater::handle()`'s own docblock). A video whose lookup
 * fails (unrecognized UID, still-unconfigured credentials, a transient
 * Cloudflare error) is simply skipped and counted, not treated as a
 * fatal error for the whole run — one bad UID must not abort backfilling
 * the rest of the catalog.
 */
final class StreamCommand
{
    /**
     * How many rows to fetch per page while resyncing.
     */
    private const BATCH_SIZE = 100;

    /**
     * Construct the command around the repository and syncer it drives.
     *
     * @param VideoMetadataRepositoryInterface $metadata_repository Lists videos to resync, and (for
     *     `--video-id`) resolves one video's current Stream UID.
     * @param StreamMetadataSyncer             $syncer               Performs each individual sync.
     */
    public function __construct(
        private readonly VideoMetadataRepositoryInterface $metadata_repository,
        private readonly StreamMetadataSyncer $syncer
    ) {
    }

    /**
     * Resync status/duration from a live Cloudflare Stream lookup — every
     * video, or one specific video with `--video-id`.
     *
     * ## OPTIONS
     *
     * [--video-id=<id>]
     * : Resync only this one video post ID, instead of every video with a Cloudflare Stream UID.
     *
     * ## EXAMPLES
     *
     *     wp tube-core stream:resync
     *     wp tube-core stream:resync --video-id=123
     *
     * @when after_wp_load
     *
     * @param array<int, string>    $args       Positional arguments (unused).
     * @param array<string, string> $assoc_args Associative arguments (--video-id).
     */
    public function resync(array $args, array $assoc_args): void
    {
        unset($args);

        if (isset($assoc_args['video-id'])) {
            $this->resync_one(absint($assoc_args['video-id']));

            return;
        }

        $this->resync_all();
    }

    /**
     * Resync a single video by post ID.
     *
     * @param int $video_id The video post ID.
     */
    private function resync_one(int $video_id): void
    {
        $metadata = $this->metadata_repository->find($video_id);

        if (null === $metadata) {
            WP_CLI::error("No Cloudflare Stream metadata found for video ID {$video_id}.");

            return;
        }

        if ($this->syncer->sync($metadata->cf_stream_uid)) {
            WP_CLI::success("Resynced video {$video_id} from Cloudflare Stream.");

            return;
        }

        WP_CLI::warning(
            "Could not resync video {$video_id} — Cloudflare Stream is unreachable, credentials aren't"
                . ' configured, or the UID is unrecognized on this account. Existing data was left unchanged.'
        );
    }

    /**
     * Resync every video with a Cloudflare Stream UID, in batches.
     */
    private function resync_all(): void
    {
        $synced = 0;
        $failed = 0;
        $offset = 0;

        do {
            $batch      = $this->metadata_repository->all_stream_uids(self::BATCH_SIZE, $offset);
            $batch_size = count($batch);

            foreach ($batch as $row) {
                if ($this->syncer->sync($row['cf_stream_uid'])) {
                    ++$synced;
                } else {
                    ++$failed;
                }
            }

            $offset += self::BATCH_SIZE;

            if ($batch_size > 0) {
                WP_CLI::log(sprintf('Resynced %d, failed %d so far...', $synced, $failed));
            }
        } while (self::BATCH_SIZE === $batch_size);

        if (0 === $synced + $failed) {
            WP_CLI::log('No videos with a Cloudflare Stream UID found.');

            return;
        }

        WP_CLI::success(
            sprintf(
                'Resynced %d video(s) from Cloudflare Stream, %d could not be resynced'
                    . ' (unreachable/unconfigured/unrecognized — existing data left unchanged).',
                $synced,
                $failed
            )
        );
    }
}
