<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Techork\Saga\Saga;
use Techork\Saga\SagaConcurrencyException;
use Techork\Saga\SagaDefinitionDriftException;
use Techork\Saga\SagaFailedException;
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
 *  - `backoff(string $transition): array` — delay between attempts in seconds
 *
 * Those govern BUSINESS failures only, and {@see failed()} compensates only for
 * those. Two other kinds of failure reach it and must NOT be rolled back:
 *
 *  - {@see SagaConcurrencyException} — the step's persist lost a race. Handled on
 *    a separate budget ({@see $concurrencyAttempt}) so a contended saga cannot
 *    burn through the retry allowance its author reserved for real errors, and so
 *    it can never reach failed() at all in the normal case.
 *  - {@see SagaDefinitionDriftException} — the code no longer fits this saga
 *    because a place or a transition was renamed, or a transition became a
 *    {@see \Techork\Saga\Signal}. Compensating would be an irreversible
 *    response to a deploy: one renamed place would mean a refund for every saga
 *    parked in it.
 *
 * @see https://laravel.com/docs/queues#max-job-attempts-and-timeout
 */
final class SagaStepJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * How many times a step may be re-dispatched purely because it could not
     * get exclusive access to the saga. Each round re-reads the current state,
     * so the sequence converges: either the transition is no longer fireable
     * (the job returns silently) or it now goes through.
     *
     * INVARIANT: MAX_CONCURRENCY_ATTEMPTS × (CacheSagaLock's $waitSeconds +
     * CONCURRENCY_RETRY_DELAY) must exceed the lock's $ttlSeconds. When a
     * worker is killed mid-step its lock survives until the TTL, and a step
     * whose budget runs out earlier gives up on a saga that would have become
     * available. With the defaults here — 12 × (3 + 15) = 216s against a 120s
     * TTL — the lock always expires first. Shortening this, or lengthening the
     * TTL, reintroduces stranded sagas.
     */
    private const MAX_CONCURRENCY_ATTEMPTS = 12;

    /** Seconds to wait before re-reading the winner's state. */
    private const CONCURRENCY_RETRY_DELAY = 15;

    /**
     * @param  class-string<Saga>  $sagaClass
     * @param  int<1, max>  $concurrencyAttempt  which persist-race round this is;
     *                                           tracked separately from Laravel's attempts()
     */
    public function __construct(
        public readonly string $sagaClass,
        public readonly string $sagaId,
        public readonly string $transition,
        public readonly int $concurrencyAttempt = 1,
    ) {}

    public function __invoke(SagaRunner $runner, Container $container): void
    {
        /** @var Saga $saga */
        $saga = $container->make($this->sagaClass);

        try {
            $runner->run($saga, $this->sagaId, $this->transition);
        } catch (SagaConcurrencyException $e) {
            // Another worker advanced the saga first. The saga is healthy and
            // owned by the winner — letting this bubble would mark the job
            // failed and compensate a saga that nothing is wrong with, rolling
            // back the winner's completed work. Re-dispatch instead.
            if ($this->concurrencyAttempt >= self::MAX_CONCURRENCY_ATTEMPTS) {
                throw $e;
            }

            $this->dispatchConcurrencyRetry($container);
        }
    }

    /**
     * Queues a fresh job for the same step, preserving routing, on the
     * persist-race budget rather than Laravel's attempt counter.
     */
    private function dispatchConcurrencyRetry(Container $container): void
    {
        $retry = new self(
            $this->sagaClass,
            $this->sagaId,
            $this->transition,
            $this->concurrencyAttempt + 1,
        );

        if ($this->connection !== null) {
            $retry->onConnection($this->connection);
        }
        if ($this->queue !== null) {
            $retry->onQueue($this->queue);
        }
        $retry->delay(self::CONCURRENCY_RETRY_DELAY);

        $container->make(BusDispatcher::class)->dispatch($retry);
    }

    /**
     * Max attempts — delegates to the saga's `tries()` method.
     *
     * Returns 1 (no retry) when the saga does not declare a policy, so a
     * failing business step goes straight to compensation rather than running
     * its action again.
     *
     * Be clear about what that does and does not buy you. It keeps a *business*
     * failure from re-running the action, and it stops a lock conflict from
     * compensating a healthy saga, because conflicts are handled on the
     * separate budget in {@see __invoke()}. It does NOT make the action
     * exactly-once: {@see SagaRunner::run()} calls the action before it
     * persists, so a step that ran and then failed to persist is re-dispatched
     * and the action runs again. Transition actions must be idempotent
     * regardless of this value — no queue offers exactly-once delivery.
     *
     * Return 0 for unlimited retries (Laravel convention).
     */
    public function tries(): int
    {
        $saga = $this->resolveSaga();

        if (\method_exists($saga, 'tries')) {
            return $saga->tries($this->transition);
        }

        return 1;
    }

    /**
     * Backoff schedule — delegates to the saga's `backoff()` method.
     *
     * Returns null, not [], when the saga declares no policy: Laravel treats
     * only null as "no policy" and would otherwise serialise [] to the empty
     * string, which overrides the worker's --backoff with a zero delay.
     *
     * @return list<int>|int|null seconds between attempts
     */
    public function backoff(): array|int|null
    {
        $saga = $this->resolveSaga();

        if (\method_exists($saga, 'backoff')) {
            return $saga->backoff($this->transition);
        }

        return null;
    }

    /**
     * Called by the queue worker when all retries are exhausted.
     *
     * Rolls the saga back — this transition first, then every applied one in
     * reverse — and deletes the state only if every compensation succeeded.
     *
     * Compensation is skipped entirely for a lost race and for definition drift —
     * see the class docblock for why neither is a business failure.
     *
     * A rollback that itself fails is the most dangerous outcome a saga has, so
     * it is never swallowed: the errors are logged at `critical` and rethrown as
     * {@see SagaFailedException}, which carries both the original cause and the
     * compensation failures. The saga row survives so the rollback can be
     * retried — nothing marks it as needing attention, so these log lines and the
     * failed_jobs entry are the only signal an operator gets.
     */
    public function failed(Throwable $exception): void
    {
        if ($exception instanceof SagaConcurrencyException) {
            // Last line of defence. The saga belongs to whoever holds it;
            // compensating here would roll back their work while this worker's
            // own side effect — never recorded in history — stays uncompensated.
            // The job is already recorded in failed_jobs for an operator to see.
            return;
        }

        if ($exception instanceof SagaDefinitionDriftException) {
            // The code no longer fits this saga: a place or a transition was
            // renamed, or an existing transition became a Signal. Rolling back is
            // the wrong and irreversible response to a deploy — a one-word rename
            // would mean a refund for every saga parked in that place. Leave the
            // saga alone and make it loud instead.
            $this->logNeedsAttention($exception);

            return;
        }

        $errors = $this->container()->make(SagaRunner::class)->compensateAndDelete(
            $this->resolveSaga(),
            $this->sagaId,
            // The failing transition is absent from history, so name it here or
            // the one step that certainly ran is never undone.
            $this->transition,
            $exception,
        );

        if ($errors === []) {
            return;
        }

        $this->logCompensationFailures($errors);

        throw new SagaFailedException(
            \sprintf(
                "Saga '%s' could not be rolled back after '%s' failed: %d compensation(s) threw. "
                . 'The saga state was kept for retry.',
                $this->sagaId,
                $this->transition,
                \count($errors),
            ),
            $exception,
            $errors,
        );
    }

    private function logNeedsAttention(SagaDefinitionDriftException $e): void
    {
        $this->log('Saga needs attention: the definition no longer fits it', $e);
    }

    /** @param Throwable[] $errors */
    private function logCompensationFailures(array $errors): void
    {
        foreach ($errors as $error) {
            $this->log('Saga compensation failed', $error);
        }
    }

    /**
     * Logs at `critical` when a logger is bound.
     *
     * Both cases this serves — a rollback that failed, and a saga the code no
     * longer fits — are deliberately NOT compensated, so the log line and the
     * failed_jobs row are the only signal an operator gets.
     */
    private function log(string $message, Throwable $cause): void
    {
        $container = $this->container();
        if (! $container->bound(LoggerInterface::class)) {
            return;
        }

        /** @var LoggerInterface $logger */
        $logger = $container->make(LoggerInterface::class);

        $logger->critical($message, [
            'saga' => $this->sagaClass,
            'saga_id' => $this->sagaId,
            'transition' => $this->transition,
            'exception' => $cause,
        ]);
    }

    public function displayName(): string
    {
        return \sprintf('Saga[%s::%s#%s]', $this->sagaClass, $this->transition, $this->sagaId);
    }

    private function resolveSaga(): Saga
    {
        /** @var Saga $saga */
        $saga = $this->container()->make($this->sagaClass);

        return $saga;
    }

    /**
     * The global `app()` helper ships with laravel/framework, which this
     * package does not depend on — only the individual illuminate components
     * do. Going through the container directly keeps these methods callable
     * (and testable) with nothing but illuminate/container present.
     */
    private function container(): Container
    {
        return \Illuminate\Container\Container::getInstance();
    }
}
