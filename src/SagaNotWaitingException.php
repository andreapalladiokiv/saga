<?php

declare(strict_types=1);

namespace Techork\Saga;

/**
 * Raised when a signal does not land.
 *
 * Two things can stop it, and this is the type both share: the saga is there but
 * nothing enabled accepts the payload, or it is not there at all
 * ({@see SagaNotFoundException}). The distinction matters to whoever is
 * diagnosing it and almost never to the code, because the cause is usually the
 * same one — the same redelivery, caught a moment earlier or a moment later.
 *
 * Usually a bug worth hearing about: the payload is wrong, or the id is, or the
 * saga is moving or stalled rather than parked, and the message names what it is
 * in fact waiting for. It is a distinct type only so that ONE caller can treat it
 * as routine — code reacting to an at-least-once announcement.
 *
 * A saga that ends announces itself once it can, and the announcement may be
 * redelivered ({@see SagaRunner::COMPLETED}). A listener turning that
 * announcement into a signal will therefore sometimes arrive at a saga that
 * already consumed the first one and moved on — or that finished and deleted its
 * row in the meantime. Both are the same duplicate, and catching the base type is
 * how the listener says so:
 *
 *     try {
 *         $runner->signal($checkout, $checkoutId, new PaymentAuthorized(...));
 *     } catch (SagaNotWaitingException) {
 *         // already applied on an earlier delivery
 *     }
 *
 * Catch it only where a duplicate is genuinely expected. Everywhere else letting
 * it escape is the point — which is the whole reason a missed signal is raised
 * rather than returned. A return value says nothing about whether ignoring it is
 * safe, and nothing in this package ever read the one that used to be here.
 */
class SagaNotWaitingException extends SagaException
{
}
