<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Call;

/** What a caller says DOWN to the child it launched, once it has settled its own books. */
final class CaptureRequested
{
    public function __construct(public string $amount) {}
}
