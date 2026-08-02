<?php
/**
 * Integration tests for the import pipeline against a real database.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Integration\Import;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tube_Core\Events\Dispatcher;
use Tube_Core\Events\EventCatalog;
use Tube_Core\Events\WordPressHookBus;
use Tube_Core\Import\BatchProcessor;
use Tube_Core\Import\Repositories\ImportQueueRepository;
use Tube_Core\Import\VideoImporter;
use Tube_Core\Video\Repositories\VideoMetadataRepository;

/**
 * Exercises ImportQueueRepository, VideoImporter, and BatchProcessor
 * together against the real wp_tube_import_queue / wp_tube_video_metadata
 * tables and real wp_posts — no fakes. Each test enqueues under a
 * unique source_key prefix and cleans up everything it created in
 * tearDown(), so tests never depend on (or pollute) each other or any
 * pre-existing data in the database.
 */
final class ImportPipelineIntegrationTest extends TestCase
{
    /**
     * The queue repository under test.
     *
     * @var ImportQueueRepository
     */
    private ImportQueueRepository $queue_repository;

    /**
     * The real metadata repository VideoImporter/BatchProcessor write through.
     *
     * @var VideoMetadataRepository
     */
    private VideoMetadataRepository $metadata_repository;

    /**
     * The real importer under test.
     *
     * @var VideoImporter
     */
    private VideoImporter $importer;

    /**
     * The real dispatcher, backed by WordPress's action hooks.
     *
     * @var Dispatcher
     */
    private Dispatcher $dispatcher;

    /**
     * The processor under test.
     *
     * @var BatchProcessor
     */
    private BatchProcessor $processor;

    /**
     * Video post IDs created during a test, deleted in tearDown().
     *
     * @var list<int>
     */
    private array $created_post_ids = [];

    /**
     * Build real collaborators for each test.
     */
    protected function setUp(): void
    {
        $this->queue_repository    = new ImportQueueRepository();
        $this->metadata_repository = new VideoMetadataRepository();
        $this->importer            = new VideoImporter($this->metadata_repository);
        $this->dispatcher          = new Dispatcher(new WordPressHookBus());
        $this->processor           = new BatchProcessor($this->queue_repository, $this->importer, $this->dispatcher);
        $this->created_post_ids    = [];
    }

    /**
     * Remove every row/post this test created, regardless of prefix.
     *
     * @param string $source_key_prefix The unique prefix this test enqueued under.
     *
     * @throws RuntimeException If a query template is malformed (a bug in this method, not in any argument).
     */
    private function cleanup(string $source_key_prefix): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $delete_queue_rows_sql = $wpdb->prepare(
            'DELETE FROM %i WHERE source_key LIKE %s',
            $wpdb->prefix . 'tube_import_queue',
            $wpdb->esc_like($source_key_prefix) . '%'
        );

        if (null === $delete_queue_rows_sql) {
            throw new RuntimeException(
                'wpdb::prepare() returned null for the queue cleanup query in ' . self::class . '.'
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test cleanup against a dedicated custom table; $delete_queue_rows_sql *is* $wpdb->prepare()'d above.
        $wpdb->query($delete_queue_rows_sql);

        foreach ($this->created_post_ids as $post_id) {
            $delete_metadata_row_sql = $wpdb->prepare(
                'DELETE FROM %i WHERE video_id = %d',
                $wpdb->prefix . 'tube_video_metadata',
                $post_id
            );

            if (null === $delete_metadata_row_sql) {
                throw new RuntimeException(
                    'wpdb::prepare() returned null for the metadata cleanup query in ' . self::class . '.'
                );
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test cleanup against a dedicated custom table; $delete_metadata_row_sql *is* $wpdb->prepare()'d above.
            $wpdb->query($delete_metadata_row_sql);

            wp_delete_post($post_id, true);
        }
    }

    /**
     * `bulk_enqueue()` silently ignores a repeated source_key instead of
     * creating a second row for it.
     */
    public function test_bulk_enqueue_ignores_duplicate_source_keys(): void
    {
        $prefix     = 'imp-dedup-' . uniqid('', true) . '-';
        $source_key = $prefix . '1';

        try {
            $this->queue_repository->bulk_enqueue(
                [
                    [
                        'source_key' => $source_key,
                        'payload'    => [
                            'title'         => 'A',
                            'cf_stream_uid' => 'uid-a',
                        ],
                    ],
                    [
                        'source_key' => $source_key,
                        'payload'    => [
                            'title'         => 'A duplicate attempt',
                            'cf_stream_uid' => 'uid-a-dup',
                        ],
                    ],
                ]
            );

            self::assertSame(1, $this->count_rows_for_prefix($prefix));
        } finally {
            $this->cleanup($prefix);
        }
    }

    /**
     * `claim_batch()` claims pending rows, and automatically resumes
     * (reclaims) a row abandoned mid-processing by a crashed worker —
     * "resume automatically after interruption."
     */
    public function test_claim_batch_resumes_a_stale_processing_row(): void
    {
        $prefix = 'imp-resume-' . uniqid('', true) . '-';

        try {
            $this->queue_repository->bulk_enqueue(
                [
                    [
                        'source_key' => $prefix . 'fresh',
                        'payload'    => [
                            'title'         => 'Fresh',
                            'cf_stream_uid' => 'uid-fresh',
                        ],
                    ],
                    [
                        'source_key' => $prefix . 'crashed',
                        'payload'    => [
                            'title'         => 'Crashed',
                            'cf_stream_uid' => 'uid-crashed',
                        ],
                    ],
                ]
            );

            $first_claim = $this->queue_repository->claim_batch(10, 600);
            self::assertCount(2, $first_claim);

            // Simulate a worker that claimed "crashed" and then died
            // before finishing: its claimed_at is long in the past.
            $crashed_id = null;

            foreach ($first_claim as $item) {
                if ($prefix . 'crashed' === $item['source_key']) {
                    $crashed_id = $item['id'];
                }
            }

            self::assertIsInt($crashed_id);
            $this->backdate_claimed_at($crashed_id, 3600);

            // claim_batch() identifies "what I just claimed" by matching
            // claimed_at against this call's own current_time() to the
            // second (documented, accepted precision in
            // ImportQueueRepository's own class docblock) -- sleeping past
            // the second boundary here keeps this test from tripping over
            // that same precision limit against "fresh"'s claimed_at from
            // the first claim_batch() call above.
            sleep(1);

            // A short staleness threshold means only "crashed" qualifies
            // for reclaim; "fresh" is still legitimately processing.
            $second_claim = $this->queue_repository->claim_batch(10, 30);

            self::assertCount(1, $second_claim);
            self::assertSame($prefix . 'crashed', $second_claim[0]['source_key']);
        } finally {
            $this->cleanup($prefix);
        }
    }

    /**
     * A successful import writes wp_tube_video_metadata and creates a
     * draft video post; re-importing the same Cloudflare Stream UID
     * under a different source_key returns the same video instead of
     * creating a second one (content-level duplicate detection).
     */
    public function test_successful_import_and_content_level_duplicate_detection(): void
    {
        $prefix        = 'imp-success-' . uniqid('', true) . '-';
        $cf_stream_uid = 'uid-' . uniqid('', true);

        try {
            $this->queue_repository->bulk_enqueue(
                [
                    [
                        'source_key' => $prefix . '1',
                        'payload'    => [
                            'title'         => 'Video One',
                            'cf_stream_uid' => $cf_stream_uid,
                        ],
                    ],
                ]
            );

            $result = $this->processor->process(10);
            self::assertSame(
                [
                    'completed' => 1,
                    'retried'   => 0,
                    'failed'    => 0,
                ],
                $result
            );

            $video_id = $this->metadata_repository->find_video_id_by_stream_uid($cf_stream_uid);
            self::assertIsInt($video_id);
            $this->created_post_ids[] = $video_id;

            $post = get_post($video_id);
            self::assertNotNull($post);
            self::assertSame('draft', $post->post_status);

            // Re-queue the same Cloudflare Stream UID under a new source_key.
            $this->queue_repository->bulk_enqueue(
                [
                    [
                        'source_key' => $prefix . '2-retry-of-same-content',
                        'payload'    => [
                            'title'         => 'Video One Again',
                            'cf_stream_uid' => $cf_stream_uid,
                        ],
                    ],
                ]
            );

            $second_result = $this->processor->process(10);
            self::assertSame(
                [
                    'completed' => 1,
                    'retried'   => 0,
                    'failed'    => 0,
                ],
                $second_result
            );

            self::assertSame(
                $video_id,
                $this->metadata_repository->find_video_id_by_stream_uid($cf_stream_uid)
            );
            self::assertSame(1, $this->count_metadata_rows_for_uid($cf_stream_uid));
        } finally {
            $this->cleanup($prefix);
        }
    }

    /**
     * An item that fails on every attempt is retried until its
     * max_attempts is exhausted, then marked permanently failed and
     * announced via IMPORT_ITEM_FAILED — never announced while retries remain.
     */
    public function test_retry_then_permanent_failure(): void
    {
        $prefix = 'imp-fail-' . uniqid('', true) . '-';

        try {
            // Missing "title" -> VideoImporter always throws, deterministically, on every attempt.
            $this->queue_repository->bulk_enqueue(
                [
                    [
                        'source_key' => $prefix . '1',
                        'payload'    => ['cf_stream_uid' => 'uid-missing-title'],
                    ],
                ]
            );

            $captured_failed = [];
            $this->dispatcher->listen(
                EventCatalog::IMPORT_ITEM_FAILED,
                static function (array $payload) use (&$captured_failed): void {
                    $captured_failed[] = $payload;
                }
            );

            // First attempt: retried, not yet announced failed (default max_attempts is 5).
            $first_result = $this->processor->process(10);
            self::assertSame(
                [
                    'completed' => 0,
                    'retried'   => 1,
                    'failed'    => 0,
                ],
                $first_result
            );
            self::assertSame([], $captured_failed);

            // Force this row to its last remaining attempt so the next
            // failure exhausts max_attempts deterministically, without
            // looping process() five times.
            $this->force_one_attempt_remaining($prefix . '1');

            $second_result = $this->processor->process(10);
            self::assertSame(
                [
                    'completed' => 0,
                    'retried'   => 0,
                    'failed'    => 1,
                ],
                $second_result
            );
            self::assertCount(1, $captured_failed);
            self::assertSame($prefix . '1', $this->source_key_for_last_error($captured_failed));
        } finally {
            $this->cleanup($prefix);
        }
    }

    /**
     * Count wp_tube_import_queue rows under a given source_key prefix.
     *
     * @param string $prefix The unique prefix this test enqueued under.
     */
    private function count_rows_for_prefix(string $prefix): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion against a dedicated custom table.
        $count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE source_key LIKE %s',
                $wpdb->prefix . 'tube_import_queue',
                $wpdb->esc_like($prefix) . '%'
            )
        );

        return (int) $count;
    }

    /**
     * Count wp_tube_video_metadata rows for a given Cloudflare Stream UID.
     *
     * @param string $cf_stream_uid The UID to count rows for.
     */
    private function count_metadata_rows_for_uid(string $cf_stream_uid): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion against a dedicated custom table.
        $count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE cf_stream_uid = %s',
                $wpdb->prefix . 'tube_video_metadata',
                $cf_stream_uid
            )
        );

        return (int) $count;
    }

    /**
     * Push a claimed row's claimed_at into the past, simulating a worker
     * that claimed it and then crashed before finishing.
     *
     * @param int $id             The queue row's ID.
     * @param int $seconds_in_past How far into the past to backdate claimed_at.
     */
    private function backdate_claimed_at(int $id, int $seconds_in_past): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup against a dedicated custom table.
        $wpdb->update(
            $wpdb->prefix . 'tube_import_queue',
            ['claimed_at' => gmdate('Y-m-d H:i:s', time() - $seconds_in_past)],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Force a pending row's max_attempts down to its current attempts + 1,
     * so its next failure exhausts retries deterministically.
     *
     * @param string $source_key The row's source_key.
     *
     * @throws RuntimeException If the query template is malformed (a bug in this method, not in $source_key).
     */
    private function force_one_attempt_remaining(string $source_key): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $sql = $wpdb->prepare(
            'UPDATE %i SET max_attempts = attempts + 1 WHERE source_key = %s',
            $wpdb->prefix . 'tube_import_queue',
            $source_key
        );

        if (null === $sql) {
            throw new RuntimeException(
                'wpdb::prepare() returned null for the max_attempts test-setup query in ' . self::class . '.'
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test setup against a dedicated custom table; $sql *is* $wpdb->prepare()'d above.
        $wpdb->query($sql);
    }

    /**
     * Extract the source_key that produced the (only) captured
     * IMPORT_ITEM_FAILED payload, by looking its queue_id back up.
     *
     * @param list<array{queue_id: int, error_message: string}> $captured_failed Captured dispatcher payloads.
     */
    private function source_key_for_last_error(array $captured_failed): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API.

        $queue_id = $captured_failed[0]['queue_id'];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion against a dedicated custom table.
        $source_key = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT source_key FROM %i WHERE id = %d',
                $wpdb->prefix . 'tube_import_queue',
                $queue_id
            )
        );

        return (string) $source_key;
    }
}
