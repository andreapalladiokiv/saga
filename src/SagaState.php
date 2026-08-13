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
 *
 * That is the whole row, and the absences are deliberate. There is no status
 * field: moving, parked and stalled are all derived from the definition and the
 * marking — parked means everything fireable is a {@see Signal}. There is no
 * record of what has been dispatched: duplicates are made harmless by the saga
 * lock plus the can() check, so tracking them would buy job count, not
 * correctness. And a rollback that did not finish is journalled into `history`
 * under {@see SagaRunner::ROLLBACK_FAILED} rather than given a column of its own.
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
