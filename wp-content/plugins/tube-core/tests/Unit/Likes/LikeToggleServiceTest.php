<?php
/**
 * Unit tests for LikeToggleService.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Likes;

use PHPUnit\Framework\TestCase;
use Tube_Core\Likes\LikeToggleService;
use Tube_Core\Tests\Unit\Likes\Fixtures\InMemoryLikeRepository;
use Tube_Core\Tests\Unit\Views\Fixtures\InMemoryVideoStatisticsRepository;

/**
 * Exercises LikeToggleService's toggle logic against fakes — no WordPress, no live database.
 */
final class LikeToggleServiceTest extends TestCase
{
    /**
     * The fake like-rows repository.
     *
     * @var InMemoryLikeRepository
     */
    private InMemoryLikeRepository $likes;

    /**
     * The fake statistics repository (holds likes_total).
     *
     * @var InMemoryVideoStatisticsRepository
     */
    private InMemoryVideoStatisticsRepository $statistics;

    /**
     * The service under test.
     *
     * @var LikeToggleService
     */
    private LikeToggleService $service;

    /**
     * Build a fresh service and fakes for each test.
     */
    protected function setUp(): void
    {
        $this->likes      = new InMemoryLikeRepository();
        $this->statistics = new InMemoryVideoStatisticsRepository();
        $this->service    = new LikeToggleService($this->likes, $this->statistics);
    }

    /**
     * Liking a video not currently liked adds the like and bumps likes_total by 1.
     */
    public function test_toggle_likes_a_video_not_currently_liked(): void
    {
        $result = $this->service->toggle(7, null, 42);

        self::assertSame(
            [
                'liked'       => true,
                'likes_total' => 1,
            ],
            $result
        );
        self::assertTrue($this->likes->has_liked(7, null, 42));
    }

    /**
     * Toggling an already-liked video unlikes it and decrements likes_total by 1.
     */
    public function test_toggle_unlikes_a_video_already_liked(): void
    {
        $this->service->toggle(7, null, 42);

        $result = $this->service->toggle(7, null, 42);

        self::assertSame(
            [
                'liked'       => false,
                'likes_total' => 0,
            ],
            $result
        );
        self::assertFalse($this->likes->has_liked(7, null, 42));
    }

    /**
     * A guest identity (visitor_token, no user_id) works identically to a logged-in user.
     */
    public function test_toggle_works_for_a_guest_visitor_token(): void
    {
        $result = $this->service->toggle(null, 'guest-token-abc', 42);

        self::assertSame(
            [
                'liked'       => true,
                'likes_total' => 1,
            ],
            $result
        );
        self::assertTrue($this->likes->has_liked(null, 'guest-token-abc', 42));
    }

    /**
     * Two different viewers liking the same video both count — likes_total reaches 2, not 1.
     */
    public function test_two_different_viewers_liking_the_same_video_both_count(): void
    {
        $this->service->toggle(7, null, 42);
        $result = $this->service->toggle(9, null, 42);

        self::assertSame(
            [
                'liked'       => true,
                'likes_total' => 2,
            ],
            $result
        );
    }

    /**
     * Liking one video never affects another video's counter.
     */
    public function test_likes_are_scoped_per_video(): void
    {
        $this->service->toggle(7, null, 42);
        $this->service->toggle(7, null, 99);

        self::assertSame(1, $this->statistics->likes_total(42));
        self::assertSame(1, $this->statistics->likes_total(99));
    }
}
