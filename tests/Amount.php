<?php

declare(strict_types=1);

namespace Techork\Saga\Tests;

final class Amount
{
    public function __construct(public int $cents) {}
}
