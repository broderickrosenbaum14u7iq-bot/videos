<?php
/**
 * Integration tests for CommentRootLockRepository, against a real database.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Tests\Integration\Comments;

use PHPUnit\Framework\TestCase;
use Tube_Comments\Comments\Repositories\CommentRootLockRepository;

/**
 * `CommentRootLockRepository` is the sole enforcement mechanism behind
 * "at most one root comment per video per rolling 24-hour window" — this
 * confirms its atomicity, expiry, and read-only status query against a
 * real MySQL/MariaDB table, including a genuine multi-process race (see
 * {@see self::test_20_parallel_attempts_result_in_exactly_one_success()}),
 * which no in-process Unit test could ever exercise (PHP-CLI is
 * single-threaded, so a same-process "race" is never actually racing).
 */
final class CommentRootLockRepositoryIntegrationTest extends TestCase
{
    /**
     * The repository under test.
     *
     * @var CommentRootLockRepository
     */
    private CommentRootLockRepository $repository;

    /**
     * A real WP_User created for the test.
     *
     * @var int
     */
    private int $user_id;

    /**
     * A real video post created for the test.
     *
     * @var int
     */
    private int $video_id;

    /**
     * Build a real repository, a real member, and a real video for each test.
     */
    protected function setUp(): void
    {
        $this->repository = new CommentRootLockRepository();

        $user_id = wp_insert_user(
            [
                'user_login' => 'root-lock-it-' . uniqid('', true),
                'user_email' => uniqid('root-lock-it-', true) . '@example.com',
                'user_pass'  => wp_generate_password(),
                'role'       => 'subscriber',
            ]
        );

        self::assertIsInt($user_id);
        $this->user_id = $user_id;

        $video_id = wp_insert_post(
            [
                'post_type'   => 'video',
                'post_title'  => 'CommentRootLockRepository Integration Test Video',
                'post_status' => 'draft',
            ],
            true
        );

        self::assertIsInt($video_id);
        $this->video_id = $video_id;
    }

    /**
     * Delete the lock row, the member, and the video.
     */
    protected function tearDown(): void
    {
        $this->delete_lock_row();
        wp_delete_user($this->user_id);
        wp_delete_post($this->video_id, true);
    }

    /**
     * The first attempt for a (user, video) pair with no existing row always succeeds.
     */
    public function test_first_attempt_with_no_existing_lock_succeeds(): void
    {
        self::assertTrue($this->repository->try_acquire($this->user_id, $this->video_id, 86400));
    }

    /**
     * A second attempt for the same pair, still inside the window, is blocked.
     */
    public function test_second_attempt_inside_the_window_is_blocked(): void
    {
        self::assertTrue($this->repository->try_acquire($this->user_id, $this->video_id, 86400));
        self::assertFalse($this->repository->try_acquire($this->user_id, $this->video_id, 86400));
    }

    /**
     * A different video is entirely unaffected by an existing lock on another video.
     */
    public function test_a_different_video_is_unaffected(): void
    {
        $other_video_id = wp_insert_post(
            [
                'post_type'   => 'video',
                'post_title'  => 'CommentRootLockRepository Integration Test Video (other)',
                'post_status' => 'draft',
            ],
            true
        );
        self::assertIsInt($other_video_id);

        self::assertTrue($this->repository->try_acquire($this->user_id, $this->video_id, 86400));
        self::assertTrue($this->repository->try_acquire($this->user_id, $other_video_id, 86400));

        $this->delete_lock_row($other_video_id);
        wp_delete_post($other_video_id, true);
    }

    /**
     * A different user is entirely unaffected by an existing lock held by another user on the same video.
     */
    public function test_a_different_user_is_unaffected(): void
    {
        $other_user_id = wp_insert_user(
            [
                'user_login' => 'root-lock-it-other-' . uniqid('', true),
                'user_email' => uniqid('root-lock-it-other-', true) . '@example.com',
                'user_pass'  => wp_generate_password(),
                'role'       => 'subscriber',
            ]
        );
        self::assertIsInt($other_user_id);

        self::assertTrue($this->repository->try_acquire($this->user_id, $this->video_id, 86400));
        self::assertTrue($this->repository->try_acquire($other_user_id, $this->video_id, 86400));

        $this->delete_lock_row(null, $other_user_id);
        wp_delete_user($other_user_id);
    }

    /**
     * Once the window has elapsed (simulated by backdating the row
     * directly), a new attempt succeeds and resets the slot to now.
     */
    public function test_attempt_succeeds_again_once_the_window_has_elapsed(): void
    {
        self::assertTrue($this->repository->try_acquire($this->user_id, $this->video_id, 86400));

        $this->backdate_lock_row_by(90000);

        self::assertTrue($this->repository->try_acquire($this->user_id, $this->video_id, 86400));

        // The slot was reset to now, so an immediate follow-up attempt
        // (still within the fresh window) is blocked again.
        self::assertFalse($this->repository->try_acquire($this->user_id, $this->video_id, 86400));
    }

    /**
     * `available_at()` returns null when no lock row exists for the pair.
     */
    public function test_available_at_is_null_with_no_lock(): void
    {
        self::assertNull($this->repository->available_at($this->user_id, $this->video_id, 86400));
    }

    /**
     * `available_at()` returns null once the window has elapsed, even though a stale row remains.
     */
    public function test_available_at_is_null_once_the_window_has_elapsed(): void
    {
        self::assertTrue($this->repository->try_acquire($this->user_id, $this->video_id, 86400));
        $this->backdate_lock_row_by(90000);

        self::assertNull($this->repository->available_at($this->user_id, $this->video_id, 86400));
    }

    /**
     * `available_at()` returns a future ISO 8601 instant while the window is still active.
     */
    public function test_available_at_is_a_future_instant_while_locked(): void
    {
        self::assertTrue($this->repository->try_acquire($this->user_id, $this->video_id, 86400));

        $available_at = $this->repository->available_at($this->user_id, $this->video_id, 86400);

        self::assertIsString($available_at);
        $timestamp = strtotime($available_at);
        self::assertIsInt($timestamp);
        self::assertGreaterThan(time(), $timestamp);
    }

    /**
     * The real concurrency proof: 20 separate operating-system processes
     * attempt try_acquire() for the IDENTICAL (user, video) pair at
     * (as close as `proc_open` allows) the same instant. Exactly one may
     * succeed, per Phase "race condition protection" — this is the
     * automated equivalent of the manual "20 parallel curl requests"
     * check, run against the actual atomic primitive rather than the
     * full HTTP stack, so a failure here points straight at
     * `CommentRootLockRepository::try_acquire()` rather than anything
     * else in the request pipeline.
     */
    public function test_20_parallel_attempts_result_in_exactly_one_success(): void
    {
        $script = __DIR__ . '/concurrent_try_acquire_runner.php';
        $php    = PHP_BINARY;
        $count  = 20;

        $processes = [];
        $pipes     = [];

        for ($i = 0; $i < $count; $i++) {
            $descriptor_spec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- this IS the test: spawning real OS-level child processes is the only way to exercise a genuine multi-process race, which no in-process technique can simulate. CLI-only integration test, never reachable from a real request.
            $process = proc_open(
                [$php, $script, (string) $this->user_id, (string) $this->video_id, '86400'],
                $descriptor_spec,
                $process_pipes
            );

            self::assertNotFalse($process, 'Failed to spawn concurrent test process #' . $i . '.');

            $processes[ $i ] = $process;
            $pipes[ $i ]     = $process_pipes;

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- these are proc_open()'s own process pipes (stdin/stdout/stderr), not WordPress-managed files; WP_Filesystem has no equivalent for process I/O.
            fclose($process_pipes[0]);
        }

        $outputs = [];

        foreach ($processes as $i => $process) {
            $outputs[ $i ] = stream_get_contents($pipes[ $i ][1]);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- these are proc_open()'s own process pipes (stdin/stdout/stderr), not WordPress-managed files; WP_Filesystem has no equivalent for process I/O.
            fclose($pipes[ $i ][1]);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see the identical ignore comment immediately above.
            fclose($pipes[ $i ][2]);
            proc_close($process);
        }

        $successes = array_filter($outputs, static fn (string $output): bool => '1' === trim($output));

        self::assertCount(
            1,
            $successes,
            'Expected exactly one of ' . $count . ' concurrent try_acquire() calls to succeed; got: '
                . implode(', ', $outputs)
        );
    }

    /**
     * Directly set the lock row's created_at to $seconds in the past, bypassing try_acquire() entirely.
     *
     * @param int $seconds How far in the past to backdate created_at.
     */
    private function backdate_lock_row_by(int $seconds): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table     = $wpdb->prefix . 'tube_comment_root_locks';
        $backdated = gmdate('Y-m-d H:i:s', time() - $seconds);

        $wpdb->update(
            $table,
            ['created_at' => $backdated],
            [
                'user_id'  => $this->user_id,
                'video_id' => $this->video_id,
            ],
            ['%s'],
            ['%d', '%d']
        );
    }

    /**
     * Delete this test's lock row(s) directly.
     *
     * @param int|null $video_id Overrides $this->video_id, for a secondary video created within one test.
     * @param int|null $user_id  Overrides $this->user_id, for a secondary user created within one test.
     */
    private function delete_lock_row(?int $video_id = null, ?int $user_id = null): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $wpdb->delete(
            $wpdb->prefix . 'tube_comment_root_locks',
            [
                'user_id'  => $user_id ?? $this->user_id,
                'video_id' => $video_id ?? $this->video_id,
            ],
            ['%d', '%d']
        );
    }
}
