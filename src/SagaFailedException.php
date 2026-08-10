<?php

declare(strict_types=1);

namespace Techork\Saga;

use Throwable;

/**
 * Raised when a saga could not be rolled back.
 *
 * A transition's own failure does NOT produce this: that exception bubbles out
 * of {@see SagaRunner::run()} untouched so the queue layer can apply its retry
 * policy. This type appears one level further out, when retries are exhausted,
 * compensation runs, and at least one compensation listener itself throws — the
 * worst outcome a saga has, because the rollback is now incomplete and no
 * further automatic attempt will be made.
 *
 * {@see $cause} is the original step failure that triggered the rollback;
 * {@see $compensationErrors} holds what the compensations threw. The saga state
 * survives so the rollback can be retried once the underlying problem is fixed —
 * but nothing marks the row as needing attention, so this exception is the only
 * signal that it does.
 *
 * Thrown by {@see \Techork\Saga\Laravel\SagaStepJob::failed()}.
 */
final class SagaFailedException extends SagaException
{
    /** @param Throwable[] $compensationErrors */
    public function __construct(
        string $message,
        public readonly Throwable $cause,
        public readonly array $compensationErrors = [],
    ) {
        parent::__construct($message, 0, $cause);
    }
}
