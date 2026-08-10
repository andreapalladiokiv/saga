<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Checkout;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;
use Techork\Saga\Saga;
use Techork\Saga\Signal;

/**
 * A payable link: created today, paid tomorrow.
 *
 * The wait is visible in the graph. `payment_received` is a {@see Signal}, so the
 * runner never queues it and the saga parks in `awaiting_payment` until something
 * outside calls signal(). `expire` leaves the same place and is an ordinary
 * transition, so the runner does queue it and its guard decides whether the
 * deadline has passed — a mixed exit needs no extra machinery.
 */
final class CheckoutSaga implements Saga
{
    public function definition(): Definition
    {
        return new Definition(
            ['created', 'awaiting_payment', 'captured', 'settled', 'expired'],
            [
                new Transition('publish', 'created', 'awaiting_payment'),
                new Signal('payment_received', 'awaiting_payment', 'captured', awaits: PaymentReceived::class),
                new Transition('expire', 'awaiting_payment', 'expired'),
                new Transition('settle', 'captured', 'settled'),
            ],
            ['created'],
        );
    }
}
