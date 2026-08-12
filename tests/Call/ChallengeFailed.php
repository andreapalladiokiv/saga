<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Call;

final class ChallengeFailed
{
    public function __construct(public string $reason) {}
}
