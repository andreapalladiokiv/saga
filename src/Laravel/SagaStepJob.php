<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Techork\Saga\Saga;
use Techork\Saga\SagaRunner;
use Throwable;

/**
 * Laravel queue job that drives a single saga transition.
 *
 * The job carries only the minimum needed to resume execution:
 *  - sagaClass:  FQCN resolved from the container to obtain a fresh Saga
 *  - sagaId:     identifier of the persisted {@see \Techork\Saga\SagaState}
 *  - transition: name of the Symfony Workflow transition to execute
 *
 * Retry and failure behaviour are delegated to the saga itself using the
 * same conventions as Laravel queue jobs:
 *
 *  - `tries(string $transition): int`     — max attempts (0 = unlimited)
 *  - `backoff(string $transition): array`  — delay between attempts in seconds
 *
 * When all retries are exhausted, {@see failed()} triggers compensation
 * via {@see SagaRunner::compensateAndDelete()}.
 *
 * @see https://laravel.com/docs/queues#max-job-attempts-and-timeout
 */
final class SagaStepJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @param class-string<Saga> $sagaClass */
    public function __construct(
        public readonly string $sagaClass,
        public readonly string $sagaId,
        public readonly string $transition,
    ) {}

    public function __invoke(SagaRunner $runner, Container $container): void
    {
        /** @var Saga $saga */
        $saga = $container->make($this->sagaClass);

        $runner->run($saga, $this->sagaId, $this->transition);
    }

    /**
     * Max attempts — delegates to the saga's `tries()` method.
     *
     * Returns 1 (no retry) when the saga does not declare a policy.
     * Return 0 for unlimited retries (Laravel convention).
     */
    public function tries(): int
    {
        $saga = app($this->sagaClass);

        if (\method_exists($saga, 'tries')) {
            return $saga->tries($this->transition);
        }

        return 1;
    }

    /**
     * Backoff schedule — delegates to the saga's `backoff()` method.
     *
     * @return list<int> seconds between attempts
     */
    public function backoff(): array
    {
        $saga = app($this->sagaClass);

        if (\method_exists($saga, 'backoff')) {
            return $saga->backoff($this->transition);
        }

        return [];
    }

    /**
     * Called by the queue worker when all retries are exhausted.
     *
     * Triggers compensation in reverse order of applied transitions,
     * then deletes the saga state.
     */
    public function failed(Throwable $exception): void
    {
        /** @var Saga $saga */
        $saga = app($this->sagaClass);

        app(SagaRunner::class)->compensateAndDelete($saga, $this->sagaId);
    }

    public function displayName(): string
    {
        return \sprintf('Saga[%s::%s#%s]', $this->sagaClass, $this->transition, $this->sagaId);
    }
}
