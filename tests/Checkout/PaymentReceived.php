<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Checkout;

/** What the payment webhook hands over. */
class PaymentReceived
{
    public function __construct(
        public string $card,
        public string $address,
    ) {}
}
