<?php
/**
 * Integration tests for ImportQueueRepository's list_items()/count_items()/requeue().
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Integration\Import;

use PHPUnit\Framework\TestCase;
use Tube_Core\Import\ImportStatus;
use Tube_Core\Import\Repositories\ImportQueueRepository;

/**
 * Exercises `ImportQueueRepository::list_items()`/`count_items()`/
 * `requeue()` (Phase 10) against the real `wp_tube_import_queue` table —
 * the read/manual-retry API `tube-admin`'s import dashboard needs, which
 * didn't exist before this phase (only the aggregate
 * {@see ImportQueueRepository::status_counts()} did). Same prefix-based
 * isolation/cleanup pattern as `ImportPipelineIntegrationTest`.
 */
final class ImportQueueAdminReadApiIntegrationTest extends TestCase
{
    /**
     * The repository under test.
     *
     * @var ImportQueueRepository
     */
    private ImportQueueRepository $repository;

    /**
     * Build a real repository for each test.
     */
    protected function setUp(): void
    {
        $this->repository = new ImportQueueRepository();
    }

    /**
     * Remove every row under a given source_key prefix.
     *
     * @param string $prefix The unique prefix this test enqueued under.
     */
    private function cleanup(string $prefix): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $sql = $wpdb->prepare(
            'DELETE FROM %i WHERE source_key LIKE %s',
            $wpdb->prefix . 'tube_import_queue',
            $wpdb->esc_like($prefix) . '%'
        );

        if (null === $sql) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test cleanup against a dedicated custom table; $sql *is* $wpdb->prepare()'d above.
        $wpdb->query($sql);
    }

    /**
     * List_items()/count_items() reflect real rows, newest first, and a status filter narrows both.
     */
    public function test_list_items_and_count_items_reflect_real_rows(): void
    {
        $prefix = 'imp-list-' . uniqid('', true) . '-';

        try {
            $this->repository->bulk_enqueue(
                [
                    [
                        'source_key' => $prefix . '1',
                        'payload'    => ['cf_stream_uid' => 'uid-1'],
                    ],
                    [
                        'source_key' => $prefix . '2',
                        'payload'    => ['cf_stream_uid' => 'uid-2'],
                    ],
                ]
            );

            $items = $this->repository->list_items(null, 10, 0);
            $keys  = array_column($items, 'source_key');

            self::assertContains($prefix . '1', $keys);
            self::assertContains($prefix . '2', $keys);

            // Newest first: the second-enqueued row's ID is higher, so it sorts before the first.
            $second_index = array_search($prefix . '2', $keys, true);
            self::assertIsInt($second_index);
            self::assertSame($prefix . '2', $items[ $second_index ]['source_key']);

            self::assertSame(0, $this->repository->count_items(ImportStatus::Completed));
        } finally {
            $this->cleanup($prefix);
        }
    }

    /**
     * List_items() with a status filter returns only matching rows.
     */
    public function test_list_items_filters_by_status(): void
    {
        $prefix = 'imp-filter-' . uniqid('', true) . '-';

        try {
            $this->repository->bulk_enqueue(
                [
                    [
                        'source_key' => $prefix . '1',
                        'payload'    => ['cf_stream_uid' => 'uid-1'],
                    ],
                ]
            );

            $this->repository->claim_batch(10, 600);

            $pending    = $this->repository->list_items(ImportStatus::Pending, 10, 0);
            $processing = $this->repository->list_items(ImportStatus::Processing, 10, 0);

            self::assertSame([], array_intersect(array_column($pending, 'source_key'), [$prefix . '1']));
            self::assertContains($prefix . '1', array_column($processing, 'source_key'));
            self::assertSame(1, $this->repository->count_items(ImportStatus::Processing));
        } finally {
            $this->cleanup($prefix);
        }
    }

    /**
     * Requeue() resets a real `failed` item back to `pending` with a
     * clean slate, and returns false for an item that isn't `failed`.
     */
    public function test_requeue_resets_a_failed_item(): void
    {
        $prefix = 'imp-requeue-' . uniqid('', true) . '-';

        try {
            $this->repository->bulk_enqueue(
                [
                    [
                        'source_key' => $prefix . '1',
                        'payload'    => ['cf_stream_uid' => 'uid-1'],
                    ],
                ]
            );

            $claimed = $this->repository->claim_batch(10, 600);
            $id      = $claimed[0]['id'];

            // Drive it straight to permanently failed (max_attempts defaults to 5;
            // force it down to 1 so a single mark_failed_or_retry() call exhausts it).
            $this->force_max_attempts_to_one($id);
            $status = $this->repository->mark_failed_or_retry($id, 'synthetic failure for this test');
            self::assertSame(ImportStatus::Failed, $status);

            self::assertFalse($this->repository->requeue(999999999));
            self::assertTrue($this->repository->requeue($id));

            $items = $this->repository->list_items(null, 10, 0);
            $index = array_search($id, array_column($items, 'id'), true);
            self::assertIsInt($index);
            $row = $items[ $index ];

            self::assertSame(ImportStatus::Pending->value, $row['status']);
            self::assertSame(0, $row['attempts']);
            self::assertNull($row['last_error']);

            // A second requeue() of the now-pending (not failed) item is a no-op.
            self::assertFalse($this->repository->requeue($id));
        } finally {
            $this->cleanup($prefix);
        }
    }

    /**
     * Force a row's max_attempts to 1, so its very next failure is permanent.
     *
     * @param int $id The queue row's ID.
     */
    private function force_max_attempts_to_one(int $id): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup against a dedicated custom table.
        $wpdb->update(
            $wpdb->prefix . 'tube_import_queue',
            ['max_attempts' => 1],
            ['id' => $id],
            ['%d'],
            ['%d']
        );
    }
}
