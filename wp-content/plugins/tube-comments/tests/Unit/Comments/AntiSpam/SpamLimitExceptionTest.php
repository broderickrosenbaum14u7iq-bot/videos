<?php
/**
 * Unit tests for SpamLimitException.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Tests\Unit\Comments\AntiSpam;

use PHPUnit\Framework\TestCase;
use Tube_Comments\Comments\AntiSpam\SpamLimitException;

/**
 * Exercises SpamLimitException's carried fields — the REST controller
 * reads `code()`/`getMessage()`/`available_at()` directly to build the
 * structured 429 response.
 */
final class SpamLimitExceptionTest extends TestCase
{
    /**
     * The code, message, and available_at given to the constructor come back unchanged.
     */
    public function test_carries_code_message_and_available_at(): void
    {
        $exception = new SpamLimitException(
            'tube_comment_video_daily_limit',
            'Bạn đã bình luận video này. Bạn có thể bình luận lại sau 24 giờ.',
            '2026-08-28T09:15:00+00:00'
        );

        self::assertSame('tube_comment_video_daily_limit', $exception->code());
        self::assertSame(
            'Bạn đã bình luận video này. Bạn có thể bình luận lại sau 24 giờ.',
            $exception->getMessage()
        );
        self::assertSame('2026-08-28T09:15:00+00:00', $exception->available_at());
    }

    /**
     * `available_at()` defaults to null when not given.
     */
    public function test_available_at_defaults_to_null(): void
    {
        $exception = new SpamLimitException('tube_comment_daily_limit', 'Đã đạt giới hạn.');

        self::assertNull($exception->available_at());
    }

    /**
     * The exception is a RuntimeException, matching every other exception in this plugin.
     */
    public function test_is_a_runtime_exception(): void
    {
        $exception = new SpamLimitException('tube_comment_daily_limit', 'Đã đạt giới hạn.');

        self::assertInstanceOf(\RuntimeException::class, $exception);
    }
}
