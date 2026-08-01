<?php
/**
 * Contract for the underlying pub/sub mechanism Dispatcher runs on.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Events;

/**
 * Contract for the underlying pub/sub mechanism Dispatcher runs on.
 *
 * Dispatcher depends on this interface rather than calling WordPress's
 * `add_action()`/`do_action()` directly, so its own logic (validating
 * event names against EventCatalog, shaping the payload) can be
 * unit-tested without WordPress loaded, per the "every plugin must be
 * independently testable" rule — the same pattern
 * SchemaVersionRepositoryInterface established for MigrationRunner in
 * Phase 1. WordPressHookBus is the real, WordPress-backed implementation
 * used in production.
 */
interface HookBusInterface
{
    /**
     * Register a listener for a hook.
     *
     * @param string   $hook     The hook name.
     * @param callable $callback Invoked with the event payload.
     * @param int      $priority WordPress-style listener priority.
     *
     * @phpstan-param callable(array<string,mixed>):void $callback
     */
    public function add_action(string $hook, callable $callback, int $priority): void;

    /**
     * Fire a hook with its payload.
     *
     * @param string               $hook    The hook name.
     * @param array<string, mixed> $payload Passed through to every registered listener.
     */
    public function do_action(string $hook, array $payload): void;
}
