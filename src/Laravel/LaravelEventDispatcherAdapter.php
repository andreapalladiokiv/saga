<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel;

use Illuminate\Events\Dispatcher as LaravelDispatcher;
use Psr\EventDispatcher\StoppableEventInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Bridges Symfony's {@see EventDispatcherInterface} to Laravel's event
 * dispatcher so saga workflow events can be subscribed to with the standard
 * Laravel `Event::listen()` / `Event::subscribe()` APIs.
 *
 * The listener loop is run here rather than delegated to
 * `LaravelDispatcher::dispatch()`, because Laravel's loop differs from
 * Symfony's in two ways that both change what user code sees:
 *
 *  - Laravel BREAKS the loop when a listener returns boolean `false`
 *    (`Illuminate\Events\Dispatcher::invokeListeners`). Symfony has no such
 *    rule. Returning a bool is idiomatic Laravel, and an arrow function whose
 *    expression happens to be falsy does it by accident — which would silently
 *    disable every later listener for that event, including a guard about to
 *    call `setBlocked(true)`. Return values are therefore discarded.
 *  - Laravel has no concept of propagation stopping, while every Symfony
 *    Workflow event is a PSR-14 {@see StoppableEventInterface}. That is
 *    honoured here.
 *
 * Listeners still receive the event object as their first argument and may
 * mutate it (guards call `$event->setBlocked(true)`, transition listeners
 * mutate the typed subject via `$event->getSubject()`, and so on).
 *
 * Note the constructor takes Laravel's CONCRETE dispatcher, not
 * `Illuminate\Contracts\Events\Dispatcher`: `getListeners()` is absent from
 * that contract, and running the loop is the entire point of this class. Stock
 * Laravel binds the concrete dispatcher for the contract, so nothing changes
 * for an ordinary application; an app that binds its own contract-only
 * dispatcher must supply its own adapter.
 */
final readonly class LaravelEventDispatcherAdapter implements EventDispatcherInterface
{
    public function __construct(private LaravelDispatcher $dispatcher) {}

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $name = $eventName ?? $event::class;

        foreach ($this->dispatcher->getListeners($name) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }

            // Deliberately ignoring the return value — see the class docblock.
            $listener($name, [$event]);
        }

        return $event;
    }
}
