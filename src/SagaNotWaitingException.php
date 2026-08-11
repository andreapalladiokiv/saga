<?php

declare(strict_types=1);

namespace Techork\Saga;

/**
 * Raised when a saga is signalled with something no enabled {@see Signal}
 * accepts.
 *
 * Usually a bug worth hearing about: the payload is wrong, or the saga is moving
 * or stalled rather than parked, and the message names what it is in fact
 * waiting for. It is a distinct type only so that ONE caller can treat it as
 * routine — code reacting to an at-least-once announcement.
 *
 * A saga that ends announces itself once it can, and the announcement may be
 * redelivered ({@see SagaRunner::COMPLETED}). A listener turning that
 * announcement into a signal will therefore sometimes arrive at a saga that
 * already consumed the first one and moved on. That duplicate is not an error,
 * and catching it is how the listener says so:
 *
 *     try {
 *         $runner->signal($checkout, $checkoutId, new PaymentAuthorized(...));
 *     } catch (SagaNotWaitingException) {
 *         // already applied on an earlier delivery
 *     }
 *
 * Catch it only where a duplicate is genuinely expected. Everywhere else letting
 * it escape is the point.
 */
final class SagaNotWaitingException extends SagaException
{
}
