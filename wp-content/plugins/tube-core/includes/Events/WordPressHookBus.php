<?php
/**
 * WordPress-backed HookBusInterface implementation.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Events;

/**
 * WordPress-backed HookBusInterface implementation.
 *
 * A thin wrapper around `add_action()`/`do_action()`. Every listener is
 * registered with exactly one accepted argument (the event payload
 * array) — Dispatcher's whole public contract is `dispatch(string
 * $event, array $payload)`, so there is never a variable argument count
 * to account for here.
 */
final class WordPressHookBus implements HookBusInterface
{
    /**
     * {@inheritDoc}
     *
     * @param string   $hook     The hook name.
     * @param callable $callback Invoked with the event payload.
     * @param int      $priority WordPress-style listener priority.
     */
    public function add_action(string $hook, callable $callback, int $priority): void
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- $hook is always one of EventCatalog::all()'s tube_core.*-prefixed constants; Dispatcher guarantees this before either method here is ever called.
        add_action($hook, $callback, $priority, 1);
    }

    /**
     * {@inheritDoc}
     *
     * @param string               $hook    The hook name.
     * @param array<string, mixed> $payload Passed through to every registered listener.
     */
    public function do_action(string $hook, array $payload): void
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- $hook is always one of EventCatalog::all()'s tube_core.*-prefixed constants; Dispatcher guarantees this before either method here is ever called.
        do_action($hook, $payload);
    }
}
