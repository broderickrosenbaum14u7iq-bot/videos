<?php
/**
 * Claims and processes a batch of pending import queue items. Backs `wp tube-core import:process`.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Import;

use Throwable;
use Tube_Core\Events\Dispatcher;
use Tube_Core\Events\EventCatalog;
use Tube_Core\Import\Repositories\ImportQueueRepositoryInterface;

/**
 * Claims and processes a batch of pending import queue items — the pure
 * logic behind `wp tube-core import:process` (ARCHITECTURE.md §7, every
 * minute in steady state; run continuously for the initial bulk backfill).
 *
 * Every item's failure is caught individually (`Throwable`, not a
 * narrower exception type) so one bad item — a malformed payload, a
 * `wp_insert_post()` failure, anything — can never abort the rest of the
 * batch. This is what "10,000+ videos" actually requires operationally:
 * a single import run that keeps making progress despite individual
 * failures, not one that stops at the first bad row.
 */
final class BatchProcessor
{
    /**
     * How long an item may sit in `processing` before `claim_batch()`
     * treats it as abandoned and reclaims it — see
     * `ImportQueueRepositoryInterface::claim_batch()`'s own docblock for
     * why this is what "resume automatically after interruption" means
     * here. Comfortably longer than any single item's realistic
     * processing time (a `wp_insert_post()` call and a couple of small
     * inserts) so a slow-but-still-running worker is never mistaken for
     * a crashed one.
     */
    private const STALE_AFTER_SECONDS = 600;

    /**
     * Construct around the queue this claims from, the importer it
     * delegates to, and the dispatcher completion/failure is announced through.
     *
     * @param ImportQueueRepositoryInterface $queue_repository Claimed from, written back to.
     * @param VideoImporterInterface         $importer         Turns one item's payload into a video.
     * @param Dispatcher                     $dispatcher       Announces IMPORT_ITEM_COMPLETED/IMPORT_ITEM_FAILED.
     */
    public function __construct(
        private readonly ImportQueueRepositoryInterface $queue_repository,
        private readonly VideoImporterInterface $importer,
        private readonly Dispatcher $dispatcher
    ) {
    }

    /**
     * Claim and process up to $limit pending items.
     *
     * @param int $limit Maximum number of items to claim this run.
     *
     * @return array{completed: int, retried: int, failed: int} Counts for this run only.
     */
    public function process(int $limit): array
    {
        $batch = $this->queue_repository->claim_batch($limit, self::STALE_AFTER_SECONDS);

        $completed = 0;
        $retried   = 0;
        $failed    = 0;

        foreach ($batch as $item) {
            try {
                $video_id = $this->importer->import($item['payload']);

                $this->queue_repository->mark_completed($item['id'], $video_id);

                $this->dispatcher->dispatch(
                    EventCatalog::IMPORT_ITEM_COMPLETED,
                    [
                        'queue_id' => $item['id'],
                        'video_id' => $video_id,
                    ]
                );

                ++$completed;
            } catch (Throwable $exception) {
                $resulting_status = $this->queue_repository->mark_failed_or_retry(
                    $item['id'],
                    $exception->getMessage()
                );

                if (ImportStatus::Failed === $resulting_status) {
                    $this->dispatcher->dispatch(
                        EventCatalog::IMPORT_ITEM_FAILED,
                        [
                            'queue_id'      => $item['id'],
                            'error_message' => $exception->getMessage(),
                        ]
                    );

                    ++$failed;
                } else {
                    ++$retried;
                }
            }
        }

        return [
            'completed' => $completed,
            'retried'   => $retried,
            'failed'    => $failed,
        ];
    }
}
