<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Call;

final class CheckoutSubject
{
    public ?string $authCode = null;

    public ?string $declineReason = null;

    public function __construct(
        public string $orderId,
        public string $amount,
    ) {}
}
