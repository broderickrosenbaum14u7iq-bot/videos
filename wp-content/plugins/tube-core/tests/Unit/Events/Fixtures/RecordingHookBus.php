<?php
/**
 * Test fixture: an in-memory HookBusInterface.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Events\Fixtures;

use Tube_Core\Events\HookBusInterface;

/**
 * An in-memory HookBusInterface that records what it was asked to do,
 * instead of touching WordPress's real hook system — this is what makes
 * Dispatcher and VideoLifecycleEvents unit-testable without WordPress.
 */
final class RecordingHookBus implements HookBusInterface
{
    /**
     * Every do_action() call this bus received, in order.
     *
     * @var list<array{hook: string, payload: array<string, mixed>}>
     */
    public array $dispatched = [];

    /**
     * Every add_action() call this bus received, in order.
     *
     * @var list<array{hook: string, priority: int}>
     */
    public array $listeners = [];

    /**
     * {@inheritDoc}
     *
     * @param string   $hook     The hook name.
     * @param callable $callback Invoked with the event payload.
     * @param int      $priority WordPress-style listener priority.
     */
    public function add_action(string $hook, callable $callback, int $priority): void
    {
        $this->listeners[] = [
            'hook'     => $hook,
            'priority' => $priority,
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @param string               $hook    The hook name.
     * @param array<string, mixed> $payload Passed through to every registered listener.
     */
    public function do_action(string $hook, array $payload): void
    {
        $this->dispatched[] = [
            'hook'    => $hook,
            'payload' => $payload,
        ];
    }
}
