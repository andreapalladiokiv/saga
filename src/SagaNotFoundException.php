<?php

declare(strict_types=1);

namespace Techork\Saga;

/**
 * Raised when {@see SagaRunner::signal()} is given an id no saga holds.
 *
 * One of two unrelated things, and the runner cannot tell them apart: the saga
 * finished (or was rolled back) and its row is gone, or the id never named a saga
 * at all — a typo, a stale correlation key, a webhook wired to the wrong field.
 * Distinguishing them would mean keeping a tombstone for every saga that ever
 * ran, which is an unbounded table to catch a mistake a test catches.
 *
 * So the choice is only which one gets the benefit of the doubt, and it goes to
 * the wrong id. `signal()` takes its id from outside — a webhook, a scheduler, an
 * operator — where a mistyped string is the likelier of the two and silence makes
 * it permanent. {@see SagaRunner::run()} is the opposite and stays silent: its id
 * comes from a queue message the runner itself wrote, so the only way it goes
 * missing is the race it is documented to tolerate.
 *
 * A caller that genuinely expects late signals catches it — which is the point of
 * raising rather than returning. It says out loud that a miss is expected here,
 * where a return value that nobody is obliged to read says nothing at all.
 *
 * Extends {@see SagaNotWaitingException} because a saga that is not there is
 * certainly not waiting, and because the redelivery that finds it gone is the
 * same redelivery that would otherwise find it moved on. One catch handles both.
 */
final class SagaNotFoundException extends SagaNotWaitingException
{
}
