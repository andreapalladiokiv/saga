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
 * irreversible. Callers should PARK the saga instead
 * ({@see SagaRunner::park()}) and let a human decide.
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
