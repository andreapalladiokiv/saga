<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Call;

/**
 * No caller id, no parent class: the child is reusable and knows nothing of
 * checkouts. This object is both its state and, once it ends, its result.
 */
final class PaymentIntentSubject
{
    public ?string $authCode = null;

    public ?string $declined = null;

    public function __construct(
        public string $reference,
        public string $amount,
    ) {}
}
