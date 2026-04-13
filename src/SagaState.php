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
 */
final readonly class SagaState
{
    /**
     * @param  array<string, int>  $marking
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
