<?php
/**
 * Unit tests for Retention.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Views;

use PHPUnit\Framework\TestCase;
use Tube_Core\Tests\Unit\Views\Fixtures\InMemoryVideoViewsRepository;
use Tube_Core\Views\Retention;

/**
 * Exercises Retention against a fake repository — no database.
 */
final class RetentionTest extends TestCase
{
    /**
     * The fake repository the retention job under test purges from.
     *
     * @var InMemoryVideoViewsRepository
     */
    private InMemoryVideoViewsRepository $views_repository;

    /**
     * The retention job under test.
     *
     * @var Retention
     */
    private Retention $retention;

    /**
     * Build a fresh retention job and fake for each test.
     */
    protected function setUp(): void
    {
        $this->views_repository = new InMemoryVideoViewsRepository();
        $this->retention        = new Retention($this->views_repository);
    }

    /**
     * Purging asks the repository for a cutoff roughly 90 days in the past.
     */
    public function test_purge_uses_a_ninety_day_cutoff(): void
    {
        $this->retention->purge();

        self::assertCount(1, $this->views_repository->purge_before_calls);

        $cutoff          = strtotime($this->views_repository->purge_before_calls[0] . ' UTC');
        $expected_cutoff = time() - 90 * 86400;

        // Allow a small tolerance for the wall-clock time elapsed between
        // building $expected_cutoff and Retention computing its own — a
        // literal-second match would make this test flaky for no reason.
        self::assertEqualsWithDelta($expected_cutoff, $cutoff, 5);
    }

    /**
     * Purging returns exactly what the repository reports as deleted.
     */
    public function test_purge_returns_the_repositorys_deleted_count(): void
    {
        $this->views_repository->purge_before_return = 42;

        self::assertSame(42, $this->retention->purge());
    }
}
