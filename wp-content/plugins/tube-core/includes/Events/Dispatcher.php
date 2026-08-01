<?php
/**
 * Validated pub/sub dispatcher for tube-core's internal event catalog.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Events;

use InvalidArgumentException;

/**
 * Validated pub/sub dispatcher for tube-core's internal event catalog.
 *
 * A documented, typed wrapper around WordPress's action hook mechanism,
 * per ARCHITECTURE.md §6 — every dispatch()/listen() call is checked
 * against EventCatalog, so a typo'd or not-yet-cataloged event name
 * fails fast instead of silently never firing. This is the "stable
 * contract instead of guessing hook names by convention" §6 describes.
 *
 * Event handlers that do meaningful work (a full index rebuild, a bulk
 * cache purge) should stay fast or delegate to a queued/cron-driven
 * follow-up — this dispatcher decouples *what* reacts to *what*, it is
 * not a replacement for the background-job system in ARCHITECTURE.md §7.
 */
final class Dispatcher
{
    /**
     * Construct the dispatcher around its hook bus.
     *
     * @param HookBusInterface $hook_bus The underlying pub/sub mechanism to run on.
     */
    public function __construct(private readonly HookBusInterface $hook_bus)
    {
    }

    /**
     * Fire an event.
     *
     * @param string               $event   One of EventCatalog's constants.
     * @param array<string, mixed> $payload Passed through to every registered listener.
     *
     * @throws InvalidArgumentException If $event is not in EventCatalog::all().
     */
    public function dispatch(string $event, array $payload = []): void
    {
        $this->guard_known_event($event);

        $this->hook_bus->do_action($event, $payload);
    }

    /**
     * Register a listener for an event.
     *
     * @param string   $event    One of EventCatalog's constants.
     * @param callable $listener Invoked with the event payload.
     * @param int      $priority WordPress-style listener priority; lower runs first.
     *
     * @phpstan-param callable(array<string,mixed>):void $listener
     *
     * @throws InvalidArgumentException If $event is not in EventCatalog::all().
     */
    public function listen(string $event, callable $listener, int $priority = 10): void
    {
        $this->guard_known_event($event);

        $this->hook_bus->add_action($event, $listener, $priority);
    }

    /**
     * Reject any event name not present in EventCatalog::all().
     *
     * @param string $event The event name to check.
     *
     * @throws InvalidArgumentException If $event is not in EventCatalog::all().
     *
     * @phpstan-assert non-empty-string $event
     */
    private function guard_known_event(string $event): void
    {
        if (! in_array($event, EventCatalog::all(), true)) {
            throw new InvalidArgumentException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception message, never rendered as HTML output.
                sprintf('"%s" is not a registered tube-core event. See EVENTS.md.', $event)
            );
        }
    }
}
