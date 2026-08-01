<?php
/**
 * Unit tests for Dispatcher.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Events;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tube_Core\Events\Dispatcher;
use Tube_Core\Events\EventCatalog;
use Tube_Core\Tests\Unit\Events\Fixtures\RecordingHookBus;

/**
 * Exercises Dispatcher's validation and delegation logic against a fake
 * HookBusInterface — no WordPress dependency.
 */
final class DispatcherTest extends TestCase
{
    /**
     * The fake hook bus the dispatcher under test is wired to.
     *
     * @var RecordingHookBus
     */
    private RecordingHookBus $hook_bus;

    /**
     * The dispatcher under test.
     *
     * @var Dispatcher
     */
    private Dispatcher $dispatcher;

    /**
     * Build a fresh dispatcher and fake hook bus for each test.
     */
    protected function setUp(): void
    {
        $this->hook_bus   = new RecordingHookBus();
        $this->dispatcher = new Dispatcher($this->hook_bus);
    }

    /**
     * Dispatch() passes the event name and payload straight through to the hook bus.
     */
    public function test_dispatch_delegates_to_the_hook_bus(): void
    {
        $this->dispatcher->dispatch(EventCatalog::VIDEO_PUBLISHED, ['video_id' => 42]);

        $expected = [
            [
                'hook'    => EventCatalog::VIDEO_PUBLISHED,
                'payload' => ['video_id' => 42],
            ],
        ];

        self::assertSame($expected, $this->hook_bus->dispatched);
    }

    /**
     * Dispatch() defaults to an empty payload when none is given.
     */
    public function test_dispatch_defaults_to_an_empty_payload(): void
    {
        $this->dispatcher->dispatch(EventCatalog::VIDEO_DELETED);

        self::assertSame([], $this->hook_bus->dispatched[0]['payload']);
    }

    /**
     * Dispatch() rejects any event name not in EventCatalog.
     */
    public function test_dispatch_rejects_an_unknown_event(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->dispatcher->dispatch('not.a.real.event');
    }

    /**
     * Dispatch() never reaches the hook bus for an unknown event.
     */
    public function test_dispatch_does_not_call_the_hook_bus_for_an_unknown_event(): void
    {
        $exception_was_thrown = false;

        try {
            $this->dispatcher->dispatch('not.a.real.event');
        } catch (InvalidArgumentException) {
            $exception_was_thrown = true;
        }

        self::assertTrue($exception_was_thrown);
        self::assertSame([], $this->hook_bus->dispatched);
    }

    /**
     * Listen() registers with the hook bus at the given priority.
     */
    public function test_listen_delegates_to_the_hook_bus(): void
    {
        $this->dispatcher->listen(
            EventCatalog::VIDEO_CREATED,
            static function (): void {
            },
            20
        );

        $expected = [
            [
                'hook'     => EventCatalog::VIDEO_CREATED,
                'priority' => 20,
            ],
        ];

        self::assertSame($expected, $this->hook_bus->listeners);
    }

    /**
     * Listen() defaults to WordPress's own standard priority of 10.
     */
    public function test_listen_defaults_to_priority_ten(): void
    {
        $this->dispatcher->listen(
            EventCatalog::VIDEO_CREATED,
            static function (): void {
            }
        );

        self::assertSame(10, $this->hook_bus->listeners[0]['priority']);
    }

    /**
     * Listen() rejects any event name not in EventCatalog — catching a
     * typo'd listener registration at registration time rather than
     * leaving it silently never firing.
     */
    public function test_listen_rejects_an_unknown_event(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->dispatcher->listen(
            'not.a.real.event',
            static function (): void {
            }
        );
    }
}
