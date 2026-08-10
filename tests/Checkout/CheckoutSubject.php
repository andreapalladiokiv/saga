<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Checkout;

/**
 * The checkout's state.
 *
 * The payment fields are nullable because they genuinely do not exist until the
 * cardholder pays — and nothing has to test them, because the wait is expressed
 * by the graph rather than by a guard reading this object.
 */
final class CheckoutSubject
{
    public ?string $card = null;

    public ?string $address = null;

    public function __construct(public string $amount) {}
}
