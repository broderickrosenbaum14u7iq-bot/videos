<?php
/**
 * Unit tests for BatchProcessor.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Import;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tube_Core\Events\Dispatcher;
use Tube_Core\Events\EventCatalog;
use Tube_Core\Import\BatchProcessor;
use Tube_Core\Import\ImportStatus;
use Tube_Core\Tests\Unit\Events\Fixtures\RecordingHookBus;
use Tube_Core\Tests\Unit\Import\Fixtures\FakeVideoImporter;
use Tube_Core\Tests\Unit\Import\Fixtures\InMemoryImportQueueRepository;

/**
 * Exercises BatchProcessor against fake queue/importer and a real
 * Dispatcher wired to a fake hook bus — no database, no WordPress.
 */
final class BatchProcessorTest extends TestCase
{
    /**
     * The fake queue repository the processor under test claims from.
     *
     * @var InMemoryImportQueueRepository
     */
    private InMemoryImportQueueRepository $queue_repository;

    /**
     * The fake importer the processor under test delegates to.
     *
     * @var FakeVideoImporter
     */
    private FakeVideoImporter $importer;

    /**
     * The fake hook bus the dispatcher under test is wired to.
     *
     * @var RecordingHookBus
     */
    private RecordingHookBus $hook_bus;

    /**
     * The processor under test.
     *
     * @var BatchProcessor
     */
    private BatchProcessor $processor;

    /**
     * Build a fresh processor and fakes for each test.
     */
    protected function setUp(): void
    {
        $this->queue_repository = new InMemoryImportQueueRepository();
        $this->importer         = new FakeVideoImporter();
        $this->hook_bus         = new RecordingHookBus();
        $this->processor        = new BatchProcessor(
            $this->queue_repository,
            $this->importer,
            new Dispatcher($this->hook_bus)
        );
    }

    /**
     * An empty batch processes nothing.
     */
    public function test_empty_batch_processes_nothing(): void
    {
        $result = $this->processor->process(50);

        self::assertSame(
            [
                'completed' => 0,
                'retried'   => 0,
                'failed'    => 0,
            ],
            $result
        );
        self::assertSame([], $this->hook_bus->dispatched);
    }

    /**
     * A successful item is marked completed and announced.
     */
    public function test_successful_item_is_completed_and_announced(): void
    {
        $this->queue_repository->batch_to_return = [
            $this->item(
                1,
                [
                    'title'         => 'Video One',
                    'cf_stream_uid' => 'uid-1',
                ]
            ),
        ];
        $this->importer->results                 = [42];

        $result = $this->processor->process(50);

        self::assertSame(
            [
                'completed' => 1,
                'retried'   => 0,
                'failed'    => 0,
            ],
            $result
        );
        self::assertSame(
            [
                [
                    'id'       => 1,
                    'video_id' => 42,
                ],
            ],
            $this->queue_repository->mark_completed_calls
        );
        self::assertSame(
            [
                [
                    'hook'    => EventCatalog::IMPORT_ITEM_COMPLETED,
                    'payload' => [
                        'queue_id' => 1,
                        'video_id' => 42,
                    ],
                ],
            ],
            $this->hook_bus->dispatched
        );
    }

    /**
     * A failing item that still has retries left is requeued, not announced as failed.
     */
    public function test_failing_item_with_retries_left_is_retried_not_announced(): void
    {
        $this->queue_repository->batch_to_return              = [
            $this->item(
                2,
                [
                    'title'         => 'Video Two',
                    'cf_stream_uid' => 'uid-2',
                ]
            ),
        ];
        $this->importer->results                              = [new InvalidArgumentException('bad payload')];
        $this->queue_repository->mark_failed_or_retry_returns = [ImportStatus::Pending];

        $result = $this->processor->process(50);

        self::assertSame(
            [
                'completed' => 0,
                'retried'   => 1,
                'failed'    => 0,
            ],
            $result
        );
        self::assertSame([], $this->queue_repository->mark_completed_calls);
        self::assertSame(
            [
                [
                    'id'            => 2,
                    'error_message' => 'bad payload',
                ],
            ],
            $this->queue_repository->mark_failed_or_retry_calls
        );
        self::assertSame([], $this->hook_bus->dispatched);
    }

    /**
     * A failing item that has exhausted its retries is announced as permanently failed.
     */
    public function test_failing_item_with_no_retries_left_is_announced_failed(): void
    {
        $this->queue_repository->batch_to_return              = [
            $this->item(
                3,
                [
                    'title'         => 'Video Three',
                    'cf_stream_uid' => 'uid-3',
                ]
            ),
        ];
        $this->importer->results                              = [new RuntimeException('wp_insert_post failed')];
        $this->queue_repository->mark_failed_or_retry_returns = [ImportStatus::Failed];

        $result = $this->processor->process(50);

        self::assertSame(
            [
                'completed' => 0,
                'retried'   => 0,
                'failed'    => 1,
            ],
            $result
        );
        self::assertSame(
            [
                [
                    'hook'    => EventCatalog::IMPORT_ITEM_FAILED,
                    'payload' => [
                        'queue_id'      => 3,
                        'error_message' => 'wp_insert_post failed',
                    ],
                ],
            ],
            $this->hook_bus->dispatched
        );
    }

    /**
     * One bad item in a batch does not stop the rest from being processed.
     */
    public function test_one_bad_item_does_not_stop_the_rest_of_the_batch(): void
    {
        $this->queue_repository->batch_to_return              = [
            $this->item(
                1,
                [
                    'title'         => 'Video One',
                    'cf_stream_uid' => 'uid-1',
                ]
            ),
            $this->item(
                2,
                [
                    'title'         => 'Video Two',
                    'cf_stream_uid' => 'uid-2',
                ]
            ),
            $this->item(
                3,
                [
                    'title'         => 'Video Three',
                    'cf_stream_uid' => 'uid-3',
                ]
            ),
        ];
        $this->importer->results                              = [
            10,
            new RuntimeException('boom'),
            30,
        ];
        $this->queue_repository->mark_failed_or_retry_returns = [ImportStatus::Failed];

        $result = $this->processor->process(50);

        self::assertSame(
            [
                'completed' => 2,
                'retried'   => 0,
                'failed'    => 1,
            ],
            $result
        );
        self::assertCount(3, $this->importer->import_calls);
    }

    /**
     * Build a claimed queue item for test fixtures.
     *
     * @param int                  $id      The queue item's ID.
     * @param array<string, mixed> $payload The item's payload.
     *
     * @return array{id: int, source_key: string, payload: array<string, mixed>, attempts: int, max_attempts: int}
     */
    private function item(int $id, array $payload): array
    {
        return [
            'id'           => $id,
            'source_key'   => "source-{$id}",
            'payload'      => $payload,
            'attempts'     => 0,
            'max_attempts' => 5,
        ];
    }
}
