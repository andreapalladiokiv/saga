<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Call;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;
use Techork\Saga\Call;
use Techork\Saga\Saga;
use Techork\Saga\Signal;

/** The mistake: an id rule that ignores $attempt, so every retry names the first child. */
final class CollidingCallSaga implements Saga
{
    public function definition(): Definition
    {
        return new Definition(
            ['new', 'awaiting_payment', 'authorized', 'declined'],
            [
                new Transition('place', 'new', 'awaiting_payment'),
                new Call('pay', 'awaiting_payment', 'authorized',
                    runs: PaymentIntentSaga::class,
                    awaits: PaymentAuthorized::class,
                    subject: static fn (CheckoutSubject $s): object
                        => new PaymentIntentSubject($s->orderId, $s->amount),
                    id: static fn (PaymentIntentSubject $s, int $attempt): string => "pi-{$s->reference}"),
                new Signal('payment_declined', 'awaiting_payment', 'declined', awaits: PaymentDeclined::class),
                new Transition('retry', 'declined', 'awaiting_payment'),
            ],
            ['new'],
        );
    }
}
