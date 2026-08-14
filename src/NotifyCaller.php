<?php

declare(strict_types=1);

namespace Techork\Saga;

/**
 * Queue the {@see Call} of a caller whose child has just finished.
 *
 * Recorded rather than done, like {@see LaunchChild}, because the child reaches
 * its end inside its own lock and touching another saga from there is the shape
 * that deadlocks an inline driver. The push happens once the lock is released.
 *
 * Nothing about the result travels here. The child's row survives its own
 * completion precisely so the caller's step can read the final subject out of it,
 * which is why the queue message needs no payload.
 *
 * @internal
 */
final readonly class NotifyCaller
{
    /**
     * @param  class-string<Saga>  $callerClass  resolved by the queue layer's own container
     * @param  string  $callerTransition  the Call to fire; firing it is what collects the result
     */
    public function __construct(
        public string $callerClass,
        public string $callerId,
        public string $callerTransition,
    ) {}
}
