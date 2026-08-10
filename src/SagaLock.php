<?php

declare(strict_types=1);

namespace Techork\Saga;

/**
 * Mutual exclusion over a single saga instance.
 *
 * Every step of one saga runs inside this lock, so two workers can never
 * execute against the same saga at the same time. That is stricter than
 * protecting the marking alone, and deliberately so: a saga's subject is one
 * mutable object persisted whole on every save, so two branches that each
 * load it, mutate it and write it back silently lose one another's writes.
 * Token movements commute; arbitrary DTO mutations do not.
 *
 * The consequence is that the branches of a Petri-net fork are interleaved
 * rather than run in parallel. The saga still completes and still visits every
 * branch — it just does not overlap them in wall-clock time.
 *
 * IMPLEMENTATION NOTE: the lock is held for the whole step, including the
 * user's transition action, which may take seconds and call out to the
 * network. It must therefore NOT be an open database transaction — use an
 * atomic cache lock, an advisory lock, or anything else that tolerates being
 * held across slow work and expires on its own if the holder dies.
 */
interface SagaLock
{
    /**
     * Runs $work with exclusive access to $sagaId, waiting for the lock if
     * another worker holds it.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     *
     * @throws SagaConcurrencyException when the lock could not be acquired in
     *                                  time. That is not a business failure:
     *                                  the caller must retry the step later
     *                                  and must never compensate.
     */
    public function withLock(string $sagaId, callable $work): mixed;
}
