<?php

declare(strict_types=1);

namespace Techork\Saga;

/**
 * Hand a child's answer to whoever called it.
 *
 * @internal
 */
final readonly class DeliverReply
{
    /**
     * @param  class-string<Saga>  $callerClass
     * @param  string  $callerTransition  the Call to fire — named, not guessed from the payload's type
     * @param  int  $callerAttempt  which entry into the parking place this answer belongs to
     */
    public function __construct(
        public string $callerClass,
        public string $callerId,
        public string $callerTransition,
        public int $callerAttempt,
        public object $payload,
    ) {}
}
