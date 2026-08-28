<?php
/**
 * Unit tests for ViewBaselineSubscriber.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Views;

use PHPUnit\Framework\TestCase;
use Tube_Core\Tests\Unit\Views\Fixtures\InMemoryVideoStatisticsRepository;
use Tube_Core\Views\ViewBaselineSubscriber;

/**
 * Exercises `ViewBaselineSubscriber`'s decision logic against a fake
 * `VideoStatisticsRepositoryInterface` — no WordPress, no live database.
 *
 * `register()` itself (the real `add_action()` wiring) is not exercised
 * here — it is WordPress-hook-signature-coupled, verified live instead,
 * the same split `Tube_Cache\Events\CachePurgeSubscriberTest`'s own
 * docblock documents for exactly this reasoning.
 */
final class ViewBaselineSubscriberTest extends TestCase
{
    /**
     * The fake repository the subscriber under test writes to.
     *
     * @var InMemoryVideoStatisticsRepository
     */
    private InMemoryVideoStatisticsRepository $repository;

    /**
     * The subscriber under test.
     *
     * @var ViewBaselineSubscriber
     */
    private ViewBaselineSubscriber $subscriber;

    /**
     * Build a fresh subscriber and fake repository for each test.
     */
    protected function setUp(): void
    {
        $this->repository = new InMemoryVideoStatisticsRepository();
        $this->subscriber = new ViewBaselineSubscriber($this->repository);
    }

    /**
     * A real VIDEO_PUBLISHED-shaped payload seeds the baseline for that video.
     */
    public function test_handle_video_published_seeds_the_baseline(): void
    {
        $this->subscriber->handle_video_published(['video_id' => 42]);

        self::assertSame(
            [
                [
                    'video_id' => 42,
                    'baseline' => ViewBaselineSubscriber::BASELINE_VIEWS,
                ],
            ],
            $this->repository->ensure_baseline_calls
        );
    }

    /**
     * The baseline is exactly 1000, per the project owner's explicit decision.
     */
    public function test_baseline_is_one_thousand(): void
    {
        self::assertSame(1000, ViewBaselineSubscriber::BASELINE_VIEWS);
    }

    /**
     * A payload missing "video_id" is a silent no-op, not a fatal.
     */
    public function test_handle_video_published_ignores_a_payload_with_no_video_id(): void
    {
        $this->subscriber->handle_video_published([]);

        self::assertSame([], $this->repository->ensure_baseline_calls);
    }

    /**
     * A payload with a non-int "video_id" (a malformed/tampered event payload) is also a silent no-op.
     */
    public function test_handle_video_published_ignores_a_non_int_video_id(): void
    {
        $this->subscriber->handle_video_published(['video_id' => 'not-an-int']);

        self::assertSame([], $this->repository->ensure_baseline_calls);
    }
}
