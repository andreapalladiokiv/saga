<?php

declare(strict_types=1);

namespace Techork\Saga;

use function sprintf;

/**
 * Raised when a saga's persisted state could not be advanced because another
 * worker changed — or removed — it first.
 *
 * This is an INFRASTRUCTURE conflict, not a business failure. The transition's
 * action may well have succeeded; only the persist was rejected. The correct
 * response is to re-run the step once the winner's state is visible, and NEVER
 * to compensate: the saga is alive and owned by whoever won the race, so
 * rolling it back here would undo their work while leaving this worker's own
 * side effect — which is absent from the persisted history — uncompensated.
 *
 * {@see \Techork\Saga\Laravel\SagaStepJob} treats this separately from every
 * other Throwable for exactly that reason.
 */
final class SagaConcurrencyException extends SagaException
{
    public static function versionMismatch(string $sagaId, int $expectedVersion): self
    {
        return new self(sprintf(
            "Saga '%s' was advanced by another worker while this step was running "
            . '(expected version %d). The step must be retried against the new state, not compensated.',
            $sagaId,
            $expectedVersion,
        ));
    }

    public static function lockTimeout(string $sagaId, int $waitedSeconds): self
    {
        return new self(sprintf(
            "Timed out after %ds waiting for the lock on saga '%s' — another worker is running a step for it. "
            . 'The step must be retried, not compensated.',
            $waitedSeconds,
            $sagaId,
        ));
    }

    public static function stateVanished(string $sagaId): self
    {
        return new self(sprintf(
            "Saga '%s' no longer exists — another worker completed or compensated it "
            . 'while this step was running.',
            $sagaId,
        ));
    }
}
