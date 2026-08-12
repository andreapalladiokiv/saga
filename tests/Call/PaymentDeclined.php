<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Call;

final class PaymentDeclined
{
    public function __construct(public string $reason) {}
}
