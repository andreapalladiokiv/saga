<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel;

use Illuminate\Contracts\Events\Dispatcher as LaravelDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Bridges Symfony's {@see EventDispatcherInterface} to Laravel's event
 * dispatcher so saga workflow events can be subscribed to with the standard
 * Laravel `Event::listen()` / `Event::subscribe()` APIs.
 *
 * Each Symfony dispatch call is forwarded to Laravel as
 * `dispatch($eventName, [$event])`. Listeners receive the event object as
 * the first argument and may mutate it (guard listeners call
 * `$event->setBlocked(true)`, transition listeners mutate the typed subject
 * via `$event->getSubject()`, etc.).
 */
final readonly class LaravelEventDispatcherAdapter implements EventDispatcherInterface
{
    public function __construct(private LaravelDispatcher $dispatcher) {}

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->dispatcher->dispatch($eventName ?? $event::class, [$event]);

        return $event;
    }
}
