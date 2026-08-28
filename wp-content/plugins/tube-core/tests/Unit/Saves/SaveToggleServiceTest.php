<?php
/**
 * Unit tests for SaveToggleService.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Saves;

use PHPUnit\Framework\TestCase;
use Tube_Core\Saves\SaveToggleService;
use Tube_Core\Tests\Unit\Saves\Fixtures\InMemorySavedVideoRepository;

/**
 * Exercises SaveToggleService's toggle logic against a fake — no WordPress, no live database.
 */
final class SaveToggleServiceTest extends TestCase
{
    /**
     * The fake save-rows repository.
     *
     * @var InMemorySavedVideoRepository
     */
    private InMemorySavedVideoRepository $saves;

    /**
     * The service under test.
     *
     * @var SaveToggleService
     */
    private SaveToggleService $service;

    /**
     * Build a fresh service and fake for each test.
     */
    protected function setUp(): void
    {
        $this->saves   = new InMemorySavedVideoRepository();
        $this->service = new SaveToggleService($this->saves);
    }

    /**
     * Saving a video not currently saved marks it saved.
     */
    public function test_toggle_saves_a_video_not_currently_saved(): void
    {
        $result = $this->service->toggle(7, null, 42);

        self::assertTrue($result);
        self::assertTrue($this->saves->has_saved(7, null, 42));
    }

    /**
     * Toggling an already-saved video unsaves it.
     */
    public function test_toggle_unsaves_a_video_already_saved(): void
    {
        $this->service->toggle(7, null, 42);

        $result = $this->service->toggle(7, null, 42);

        self::assertFalse($result);
        self::assertFalse($this->saves->has_saved(7, null, 42));
    }

    /**
     * A guest identity (visitor_token, no user_id) works identically to a logged-in user.
     */
    public function test_toggle_works_for_a_guest_visitor_token(): void
    {
        $result = $this->service->toggle(null, 'guest-token-abc', 42);

        self::assertTrue($result);
        self::assertTrue($this->saves->has_saved(null, 'guest-token-abc', 42));
    }

    /**
     * Saves are scoped per video — saving one video never affects another.
     */
    public function test_saves_are_scoped_per_video(): void
    {
        $this->service->toggle(7, null, 42);

        self::assertTrue($this->saves->has_saved(7, null, 42));
        self::assertFalse($this->saves->has_saved(7, null, 99));
    }
}
