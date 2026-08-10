<?php

declare(strict_types=1);

namespace Techork\Saga;

use function implode;
use function sprintf;

/**
 * Raised when a persisted saga no longer fits the workflow definition the
 * running code declares.
 *
 * Sagas are long-running by definition, so they outlive deploys. Renaming a
 * place strands every saga parked in it; renaming a transition makes every
 * queued job and every external signal for it a permanent no-op. Neither is a
 * business failure and neither should be compensated: rolling back is exactly
 * the wrong response to "the code changed under this saga", and it is
 * irreversible. {@see \Techork\Saga\Laravel\SagaStepJob::failed()} therefore
 * declines to compensate on this, logs it at `critical` and leaves the saga
 * exactly where it is for a human to decide.
 */
final class SagaDefinitionDriftException extends SagaException
{
    /** @param list<string> $knownPlaces */
    public static function unknownPlace(string $sagaId, string $place, array $knownPlaces): self
    {
        return new self(sprintf(
            "Saga '%s' is marked in place '%s', which the current definition does not have. Known places: %s. "
            . 'The place was probably renamed or removed while this saga was in flight.',
            $sagaId,
            $place,
            implode(', ', $knownPlaces),
        ));
    }

    public static function firedSignalDirectly(string $sagaId, string $transition): self
    {
        return new self(sprintf(
            "Transition '%s' of saga '%s' is a %s and can only be fired by SagaRunner::signal(), which "
            . 'carries its payload. Calling run() on it would advance the saga past its own wait with no '
            . 'data — which is what a queued job left over from before this transition became a Signal '
            . 'would do.',
            $transition,
            $sagaId,
            Signal::class,
        ));
    }

    /** @param list<string> $knownTransitions */
    public static function unknownTransition(string $sagaId, string $transition, array $knownTransitions): self
    {
        return new self(sprintf(
            "Saga '%s' was asked to run transition '%s', which the current definition does not declare. "
            . 'Known transitions: %s. A renamed transition makes redelivered jobs and external signals '
            . 'silent no-ops, so this is reported rather than ignored.',
            $sagaId,
            $transition,
            implode(', ', $knownTransitions),
        ));
    }
}
