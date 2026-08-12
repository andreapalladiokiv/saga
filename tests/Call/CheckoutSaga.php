<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Call;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;
use Techork\Saga\Call;
use Techork\Saga\Saga;
use Techork\Saga\Signal;

/**
 * The caller. It never starts or signals anything itself — `pay` declares that
 * entering `awaiting_payment` runs a {@see PaymentIntentSaga}, and the answer
 * arrives as the transition's payload.
 *
 * `payment_declined` is an ordinary Signal out of the same place: the other
 * outcome of the same wait, which launches nothing. Two Calls there would be
 * rejected.
 */
final class CheckoutSaga implements Saga
{
    public function definition(): Definition
    {
        return new Definition(
            ['new', 'awaiting_payment', 'authorized', 'declined', 'settled', 'abandoned'],
            [
                new Transition('place', 'new', 'awaiting_payment'),

                new Call('pay', 'awaiting_payment', 'authorized',
                    runs: PaymentIntentSaga::class,
                    awaits: PaymentAuthorized::class,
                    subject: static fn (CheckoutSubject $s): object
                        => new PaymentIntentSubject($s->orderId, $s->amount)),

                new Signal('payment_declined', 'awaiting_payment', 'declined',
                    awaits: PaymentDeclined::class),

                new Transition('settle', 'authorized', 'settled'),

                // A decision, so both exits are guarded: the runner queues every
                // enabled ordinary transition, and two open doors out of one place
                // race. A second attempt re-enters the parking place and therefore
                // gets a second child rather than colliding with the first.
                new Transition('retry', 'declined', 'awaiting_payment'),
                new Transition('abandon', 'declined', 'abandoned'),
            ],
            ['new'],
        );
    }
}
