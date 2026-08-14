<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Call;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;
use Techork\Saga\Call;
use Techork\Saga\Saga;

/**
 * The caller. It never starts or signals anything itself: `pay` declares that
 * entering `awaiting_payment` runs a payment intent, and the intent's final
 * subject arrives as that transition's payload once the intent ends.
 *
 * One target, and the branch afterwards is guarded on what the caller copied out
 * of the child's subject — an outcome is data, not a second edge.
 */
final class CheckoutSaga implements Saga
{
    public function __construct(private PaymentIntentSaga $intent) {}

    public function definition(): Definition
    {
        return new Definition(
            ['new', 'awaiting_payment', 'collected', 'settled', 'abandoned'],
            [
                new Transition('place', 'new', 'awaiting_payment'),

                new Call('pay', 'awaiting_payment', 'collected',
                    runs: $this->intent,
                    subject: static fn (CheckoutSubject $s): object
                        => new PaymentIntentSubject($s->orderId, $s->amount)),

                new Transition('settle', 'collected', 'settled'),
                new Transition('abandon', 'collected', 'abandoned'),
            ],
            ['new'],
        );
    }
}
