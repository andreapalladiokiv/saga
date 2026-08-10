<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Techork\Saga\SagaQueue;
use Techork\Saga\SagaRouting;

/**
 * {@see SagaQueue} backed by the Laravel job dispatcher.
 *
 * Per-saga routing comes from {@see SagaRouting}; constructor defaults serve as
 * fallbacks. Jobs are dispatched afterCommit by default so that the idiomatic
 * `DB::transaction(fn () => $runner->start(...))` cannot hand a worker a saga id
 * that is not committed yet — `run()` would find no row and return silently by
 * design, leaving the saga parked forever with nothing queued and no error.
 */
final class LaravelSagaQueue implements SagaQueue
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly Container $container,
        private readonly ?string $connection = null,
        private readonly ?string $queue = null,
        private readonly bool $afterCommit = true,
    ) {}

    public function push(string $sagaClass, string $sagaId, string $transition, int $delaySeconds = 0): void
    {
        $job = new SagaStepJob($sagaClass, $sagaId, $transition);

        $saga = $this->container->make($sagaClass);

        $connection = ($saga instanceof SagaRouting ? $saga->connection() : null) ?? $this->connection;
        $queue = ($saga instanceof SagaRouting ? $saga->queue() : null) ?? $this->queue;

        if ($connection !== null) {
            $job->onConnection($connection);
        }
        if ($queue !== null) {
            $job->onQueue($queue);
        }
        if ($delaySeconds > 0) {
            $job->delay($delaySeconds);
        }
        if ($this->afterCommit) {
            $job->afterCommit();
        }

        $this->dispatcher->dispatch($job);
    }
}
