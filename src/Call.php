<?php

declare(strict_types=1);

namespace Techork\Saga;

use Closure;
use Symfony\Component\Workflow\Arc;

/**
 * A {@see Signal} that runs another saga while it waits.
 *
 * Entering the place a Call leaves launches {@see $runs}; the saga then parks
 * there exactly as it would on a Signal, and the only thing that moves it on is
 * the child's answer, delivered as the transition's payload. So the parent reads
 * the result with the same {@see Signal::payload()} as any other signal and does
 * whatever it likes with it — the child hands back a fact, not a decision.
 *
 *     new Call('pay', 'awaiting_payment', 'authorized',
 *         runs:    PaymentIntentSaga::class,
 *         awaits:  PaymentAuthorized::class,
 *         subject: static fn (CheckoutSubject $s): object
 *             => new PaymentIntentSubject($s->orderId, $s->amount)),
 *
 * Nothing in either saga calls the runner. That is the point, and it is what
 * makes this safe where a hand-written bridge is not: the launch and the answer
 * are performed by the runner, which puts both OUTSIDE the saga lock. A listener
 * that starts or signals another saga from inside a step holds one lock while
 * taking another, and under an inline queue driver the second saga's step comes
 * straight back for the first — two sagas, two locks, a cycle. Declaring the
 * relationship instead of coding it removes the opportunity.
 *
 * The child's id is derived, never supplied: `<parent id>/<transition>/<n>`,
 * where n counts how many times the parent has entered this place — a retry
 * loop that comes back for a second payment attempt gets a second child rather
 * than colliding with the first. Because the id is derivable, a launch lost to a
 * crash is re-derivable too: {@see SagaRunner::requeue()} recreates a child that
 * a parked parent is waiting for, and hitting
 * {@see SagaAlreadyExistsException} means it was already there.
 *
 * A place may have at most one Call leaving it — two would launch two children
 * on one entry. Other exits from the same place are ordinary Signals (the
 * child failed) or guarded Transitions (the deadline passed), and neither
 * launches anything.
 *
 * @see SagaRunner::reply() — how the child answers
 */
final class Call extends Signal
{
    /**
     * @param  string|string[]|Arc[]  $froms  where the parent parks while the child runs
     * @param  string|string[]|Arc[]  $tos    where it goes once the child answers
     * @param  class-string<Saga>  $runs  the saga to launch on entering the parking place
     * @param  class-string  $awaits  the answer type this Call accepts
     * @param  Closure(object): object  $subject  builds the child's subject from the parent's
     */
    public function __construct(
        string $name,
        string|array $froms,
        string|array $tos,
        public readonly string $runs,
        string $awaits,
        public readonly Closure $subject,
    ) {
        parent::__construct($name, $froms, $tos, $awaits);
    }

    /** The child's subject, built from the parent's. */
    public function subjectFor(object $parentSubject): object
    {
        return ($this->subject)($parentSubject);
    }
}
