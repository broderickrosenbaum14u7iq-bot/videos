<?php
/**
 * Standalone runner shelled out to (via proc_open) by
 * CommentRootLockRepositoryIntegrationTest::test_20_parallel_attempts_result_in_exactly_one_success()
 * to prove CommentRootLockRepository::try_acquire() is race-safe across
 * REAL operating-system processes, not just within one PHP process
 * (which is inherently single-threaded and could never exhibit the race
 * this class is designed to close).
 *
 * Usage: php concurrent_try_acquire_runner.php <user_id> <video_id> <window_seconds>
 * Prints exactly "1" (acquired) or "0" (blocked) to stdout.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Tube_Comments\Comments\Repositories\CommentRootLockRepository;

// Wrapped in an IIFE so its locals stay out of the global scope
// (WordPress.NamingConventions.PrefixAllGlobals) -- this file is a plain
// CLI script, not a class, so a function scope is the only way to avoid
// that without prefixed throwaway variable names.
(static function (array $argv): void {
    $user_id        = (int) ($argv[1] ?? 0);
    $video_id       = (int) ($argv[2] ?? 0);
    $window_seconds = (int) ($argv[3] ?? 86400);

    $repository = new CommentRootLockRepository();

    echo $repository->try_acquire($user_id, $video_id, $window_seconds) ? '1' : '0';
})($argv);
