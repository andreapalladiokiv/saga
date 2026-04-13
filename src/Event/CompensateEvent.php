<?php

declare(strict_types=1);

namespace Techork\Saga\Event;

/**
 * Dispatched by {@see \Techork\Saga\SagaRunner} when a saga step fails.
 *
 * One event per previously-applied transition, in reverse order, under the
 * name `saga.<FQCN>.compensate.<transition>`. Compensation listeners typically
 * perform read-model fixups, external reversals, or enqueue compensating
 * commands.
 *
 * The subject is the saga's typed subject snapshot at the moment the failing
 * step began — the same instance every compensation for this saga sees.
 * Listeners narrow to their concrete subject type with a local `@var`
 * declaration.
 */
final class CompensateEvent
{
    public function __construct(
        public readonly string $sagaClass,
        public readonly string $sagaId,
        public readonly string $transition,
        public readonly object $subject,
    ) {}
}
