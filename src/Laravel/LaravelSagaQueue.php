<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Techork\Saga\SagaQueue;

/**
 * {@see SagaQueue} backed by the Laravel job dispatcher.
 *
 * Per-saga routing: if the saga class declares public `$connection` or
 * `$queue` properties, the job is routed accordingly. Constructor defaults
 * serve as fallbacks when the saga does not declare its own routing.
 */
final class LaravelSagaQueue implements SagaQueue
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly Container $container,
        private readonly ?string $connection = null,
        private readonly ?string $queue = null,
    ) {}

    public function push(string $sagaClass, string $sagaId, string $transition): void
    {
        $job = new SagaStepJob($sagaClass, $sagaId, $transition);

        $saga = $this->container->make($sagaClass);

        $connection = $saga->connection ?? $this->connection;
        $queue = $saga->queue ?? $this->queue;

        if ($connection !== null) {
            $job->onConnection($connection);
        }
        if ($queue !== null) {
            $job->onQueue($queue);
        }

        $this->dispatcher->dispatch($job);
    }
}
