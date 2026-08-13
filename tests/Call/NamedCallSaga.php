<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Call;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;
use Techork\Saga\Call;
use Techork\Saga\Saga;
use Techork\Saga\Signal;

/**
 * A caller that names its children itself, because something outside has to find
 * them: an endpoint capturing a payment, a provider's webhook.
 *
 * The rule varies by attempt, which is the part that is easy to get wrong.
 */
final class NamedCallSaga implements Saga
{
    public function definition(): Definition
    {
        return new Definition(
            ['new', 'awaiting_payment', 'authorized', 'declined', 'settled'],
            [
                new Transition('place', 'new', 'awaiting_payment'),
                new Call('pay', 'awaiting_payment', 'authorized',
                    runs: PaymentIntentSaga::class,
                    awaits: PaymentAuthorized::class,
                    subject: static fn (CheckoutSubject $s): object
                        => new PaymentIntentSubject($s->orderId, $s->amount),
                    id: static fn (PaymentIntentSubject $s, int $attempt): string
                        => "pi-{$s->reference}-{$attempt}"),
                new Signal('payment_declined', 'awaiting_payment', 'declined', awaits: PaymentDeclined::class),
                new Transition('settle', 'authorized', 'settled'),
                new Transition('retry', 'declined', 'awaiting_payment'),
            ],
            ['new'],
        );
    }
}
