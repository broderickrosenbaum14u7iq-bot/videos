<?php
/**
 * Test fixture: an in-memory ImportQueueRepositoryInterface.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Import\Fixtures;

use Tube_Core\Import\ImportStatus;
use Tube_Core\Import\Repositories\ImportQueueRepositoryInterface;

/**
 * An in-memory ImportQueueRepositoryInterface that records what it was
 * asked to do and returns pre-programmed results — no database.
 */
final class InMemoryImportQueueRepository implements ImportQueueRepositoryInterface
{
    /**
     * Every bulk_enqueue() call this fake received, in order.
     *
     * @var list<list<array{source_key: string, payload: array<string, mixed>}>>
     */
    public array $bulk_enqueue_calls = [];

    /**
     * What bulk_enqueue() should return.
     *
     * @var int
     */
    public int $bulk_enqueue_return = 0;

    /**
     * What claim_batch() should return.
     *
     * @var list<array{id: int, source_key: string, payload: array<string, mixed>, attempts: int, max_attempts: int}>
     */
    public array $batch_to_return = [];

    /**
     * Every mark_completed() call this fake received, in order.
     *
     * @var list<array{id: int, video_id: int}>
     */
    public array $mark_completed_calls = [];

    /**
     * Every mark_failed_or_retry() call this fake received, in order.
     *
     * @var list<array{id: int, error_message: string}>
     */
    public array $mark_failed_or_retry_calls = [];

    /**
     * Scripted mark_failed_or_retry() results, consumed in call order.
     * Defaults to Pending (retry) once exhausted.
     *
     * @var list<ImportStatus>
     */
    public array $mark_failed_or_retry_returns = [];

    /**
     * What status_counts() should return.
     *
     * @var array<string, int>
     */
    public array $status_counts_to_return = [];

    /**
     * {@inheritDoc}
     *
     * @param list<array{source_key: string, payload: array<string, mixed>}> $items Items to enqueue.
     */
    public function bulk_enqueue(array $items): int
    {
        $this->bulk_enqueue_calls[] = $items;

        return $this->bulk_enqueue_return;
    }

    /**
     * {@inheritDoc}
     *
     * @param int $limit               Maximum number of items to claim.
     * @param int $stale_after_seconds How long an item may sit in `processing` before being reclaimed.
     *
     * @return list<array{id: int, source_key: string, payload: array<string, mixed>, attempts: int, max_attempts: int}>
     */
    public function claim_batch(int $limit, int $stale_after_seconds): array
    {
        return $this->batch_to_return;
    }

    /**
     * {@inheritDoc}
     *
     * @param int $id       The queue item's ID.
     * @param int $video_id The video it produced.
     */
    public function mark_completed(int $id, int $video_id): void
    {
        $this->mark_completed_calls[] = [
            'id'       => $id,
            'video_id' => $video_id,
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @param int    $id            The queue item's ID.
     * @param string $error_message What went wrong, stored for operator visibility.
     */
    public function mark_failed_or_retry(int $id, string $error_message): ImportStatus
    {
        $this->mark_failed_or_retry_calls[] = [
            'id'            => $id,
            'error_message' => $error_message,
        ];

        return array_shift($this->mark_failed_or_retry_returns) ?? ImportStatus::Pending;
    }

    /**
     * {@inheritDoc}
     *
     * @return array<string, int> Status value => count.
     */
    public function status_counts(): array
    {
        return $this->status_counts_to_return;
    }
}
