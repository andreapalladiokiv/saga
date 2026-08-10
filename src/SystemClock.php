<?php

declare(strict_types=1);

namespace Techork\Saga;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * The wall clock.
 *
 * Exists so the package does not have to reach for a framework's clock:
 * repositories only need *a* {@see ClockInterface}, and binding Carbon's
 * `FactoryImmutable` for it made the Laravel integration depend on a Carbon
 * version whose API differs across the Laravel releases the package claims to
 * support. Applications that need a frozen or offset clock bind their own.
 */
final readonly class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
