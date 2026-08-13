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
 * The child's id is derived rather than supplied at the call site. By default
 * `<parent id>/<transition>/<n>`, where n counts how many times the parent has
 * entered this place, so a retry loop that comes back for a second payment
 * attempt gets a second child instead of colliding with the first.
 *
 * That default is opaque, which is a problem when something outside has to find
 * the child later — an endpoint capturing a payment, a provider's webhook. Pass
 * {@see $id} to derive it from the child's own subject instead:
 *
 *     id: static fn (PaymentIntentSubject $s, int $attempt): string
 *         => "pi-{$s->reference}-{$attempt}",
 *
 * Derived is the load-bearing word either way: the rule is a pure function of
 * the child's subject, so {@see SagaRunner::requeue()} recomputes the same id
 * and can recreate a child that a parked parent is waiting for but which a
 * crash lost before it was started.
 *
 * A rule that ignores $attempt is safe only while each child finishes before the
 * next attempt starts; reusing the name of a saga that has ended is fine. It is
 * not safe when the caller moves on while the child is still running — a
 * deadline rather than an answer — and there the runner refuses instead of
 * guessing: it records which attempt each child belongs to, and a launch landing
 * on a row belonging to a different one is an error. Swallowing it would let the
 * retry adopt the earlier child, launch nothing, and leave that child's answer
 * arriving for an attempt that no longer exists.
 *
 * Several Calls may leave one place, which is how a saga picks WHICH saga to run
 * from what it knows — a card payment or a subscription payment out of the same
 * wait — and their guards decide. Nothing checks that the guards are exclusive:
 * entering the place starts every Call that leaves it, and with one token only
 * the first answer can fire, so the other child runs for nothing. Whether that
 * graph makes sense is the author's business; the runner executes the net as
 * written. Note that two arrows out of a PLACE are a choice — real parallelism
 * comes out of a transition, or carries an arc weight — so a diagram showing two
 * Calls on one place is not showing two branches that will both complete.
 *
 * Other exits from a parking place need not be Calls at all — an ordinary Signal
 * for 'the attempt failed', a guarded Transition for 'the deadline passed' — and
 * neither launches anything. A child whose answer is not the type its Call
 * awaits takes one of those instead, chosen by type as any signal is.
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
     * @param  Closure(object, int): string|null  $id  names the child from its own subject and the
     *                                                 attempt number; omit for the default
     */
    public function __construct(
        string $name,
        string|array $froms,
        string|array $tos,
        public readonly string $runs,
        string $awaits,
        public readonly Closure $subject,
        public readonly ?Closure $id = null,
    ) {
        parent::__construct($name, $froms, $tos, $awaits);
    }

    /** The child's subject, built from the parent's. */
    public function subjectFor(object $parentSubject): object
    {
        return ($this->subject)($parentSubject);
    }

    /**
     * The child's id under this Call's own rule, or null to use the runner's.
     *
     * Takes the child's subject rather than the parent's: what an outside caller
     * later looks the child up by — a payment intent reference, an invoice
     * number — belongs to the child.
     */
    public function idFor(object $childSubject, int $attempt): ?string
    {
        return $this->id === null ? null : ($this->id)($childSubject, $attempt);
    }
}
