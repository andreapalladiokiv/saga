<?php

declare(strict_types=1);

namespace Techork\Saga\Event;

use Throwable;

/**
 * Dispatched by {@see \Techork\Saga\SagaRunner} when a saga is rolled back.
 *
 * One event per transition that has to be undone, under the name
 * `saga.<FQCN>.compensate.<transition>`. Compensation listeners typically
 * perform read-model fixups, external reversals, or enqueue compensating
 * commands.
 *
 * Order is: the transition that FAILED first (if the caller identified one),
 * then every successfully-applied transition in reverse. The failing step is
 * included precisely because it is the one guaranteed to have run — and to
 * have run only partway. Its {@see $failed} flag is true, and listeners must
 * tolerate being called for work that may have done nothing at all: an action
 * can throw on its first line.
 *
 * The subject is the saga's typed subject as last persisted — for the failing
 * step, that is the snapshot from before it began, since a step that throws
 * never persists. Listeners narrow to their concrete subject type with a local
 * `@var` declaration.
 *
 * @template T of object
 */
final readonly class CompensateEvent
{
    public function __construct(
        public string $sagaClass,
        public string $sagaId,
        public string $transition,
        /** @var T */
        public object $subject,
        /** Why the saga is being rolled back, when the caller knows. */
        public ?Throwable $cause = null,
        /** True for the transition whose action threw, false for completed ones. */
        public bool $failed = false,
    ) {}
}
