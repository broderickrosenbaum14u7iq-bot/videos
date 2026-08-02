<?php
/**
 * Contract for wp_tube_import_queue data access.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Import\Repositories;

use Tube_Core\Import\ImportStatus;

/**
 * Contract for wp_tube_import_queue data access, per the
 * `{Noun}Repository` convention (ARCHITECTURE.md §19.4).
 *
 * Adopted per the interface-justification rule (§19.1): the real payoff
 * is a test fake `BatchProcessor` is actually unit-tested against,
 * without a live database.
 */
interface ImportQueueRepositoryInterface
{
    /**
     * Enqueue new items, one multi-row `INSERT IGNORE`
     * (ARCHITECTURE.md §19.8) against `source_key`'s `UNIQUE KEY` — an
     * item whose `source_key` is already queued (or already processed)
     * is silently skipped, not duplicated. This is the queue-level half
     * of duplicate detection; `VideoImporter`'s Cloudflare-Stream-UID
     * check is the content-level half.
     *
     * @param list<array{source_key: string, payload: array<string, mixed>}> $items Items to enqueue.
     *
     * @return int The number of items actually inserted (excludes ones skipped as already-queued duplicates).
     */
    public function bulk_enqueue(array $items): int;

    /**
     * Claim up to $limit pending items for processing.
     *
     * Two things happen here, both load-bearing for the requirements in
     * ARCHITECTURE.md §12 Phase 5: first, any item stuck in `processing`
     * for longer than $stale_after_seconds (its worker crashed or was
     * killed before marking it completed/failed) is reclaimed back to
     * `pending` — this is what "resume automatically after interruption"
     * means in practice, with no separate resume command needed. Second,
     * up to $limit genuinely-pending items (including any just
     * reclaimed) are atomically marked `processing` and returned.
     *
     * @param int $limit               Maximum number of items to claim.
     * @param int $stale_after_seconds How long an item may sit in
     *                                 `processing` before being treated as
     *                                 abandoned and reclaimed.
     *
     * @return list<array{id: int, source_key: string, payload: array<string, mixed>, attempts: int, max_attempts: int}>
     *         The claimed items.
     */
    public function claim_batch(int $limit, int $stale_after_seconds): array;

    /**
     * Mark an item permanently completed.
     *
     * @param int $id       The queue item's ID.
     * @param int $video_id The video it produced.
     */
    public function mark_completed(int $id, int $video_id): void;

    /**
     * Record a processing failure and decide, in one statement, whether
     * this item gets retried (`attempts` incremented, back to `pending`)
     * or has now exhausted `max_attempts` (`failed` permanently).
     *
     * @param int    $id            The queue item's ID.
     * @param string $error_message What went wrong, stored for operator visibility.
     *
     * @return ImportStatus The status the item now has — `Pending` if it
     *                       will be retried, `Failed` if this was the
     *                       final attempt.
     */
    public function mark_failed_or_retry(int $id, string $error_message): ImportStatus;

    /**
     * Count queue items by status, for progress tracking.
     *
     * @return array<string, int> Status value => count. Only statuses with at least one item are present.
     */
    public function status_counts(): array;
}
