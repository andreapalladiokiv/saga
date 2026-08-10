<?php

declare(strict_types=1);

namespace Techork\Saga;

/**
 * Persisted state of a saga.
 *
 *  - id:      stable identifier of the saga instance
 *  - marking: current Symfony Workflow marking (map of place name -> 1);
 *             multiple places may be present at once (fork / join)
 *  - subject: the saga's typed subject — opaque to the library, persisted via
 *             PHP `serialize()`/`unserialize()` by repositories. Must be
 *             serializable (plain DTO, no closures / resources)
 *  - history: names of successfully applied transitions, oldest first; drives
 *             reverse-order compensation when a later transition fails
 *  - version: monotonically-incremented on every save. Repositories that need
 *             optimistic locking compare-and-set on this value.
 *  - pending: transitions the runner has already put on the queue and not yet
 *             seen applied. This is what stops a completing fork branch from
 *             re-queueing its still-in-flight siblings. It records what was
 *             *dispatched*, not what happens to be enabled — a saga can sit in
 *             a wait state with transitions enabled and nothing dispatched, and
 *             those must be queued once a guard finally passes.
 *  - status:  {@see SagaStatus}. Separates a saga that is still moving from one
 *             whose rollback failed and now needs a human.
 *  - sagaClass: which {@see Saga} this row belongs to. Without it the class lived
 *             only in the queue message, so a dropped message left a row nothing
 *             could identify, let alone resume — every entry point needs a Saga
 *             the row could not supply.
 *
 * @property-read class-string<Saga>|null $sagaClass
 */
final readonly class SagaState
{
    /**
     * @param  array<string, int<1, max>>  $marking
     * @param  list<string>  $history
     */
    public function __construct(
        public string $id,
        public array $marking,
        public object $subject,
        public array $history = [],
        public int $version = 0,
    ) {}
}
