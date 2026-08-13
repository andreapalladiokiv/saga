<?php

declare(strict_types=1);

namespace Techork\Saga;

/**
 * Start the saga a {@see Call} names, because the parent just entered the place
 * that Call leaves.
 *
 * @internal
 */
final readonly class LaunchChild
{
    /**
     * @param  class-string<Saga>  $sagaClass  the child
     * @param  string  $childId  derived, never supplied — see {@see Call}
     * @param  class-string<Saga>  $callerClass
     * @param  string  $callerTransition  the Call's name, so the answer knows which edge to fire
     * @param  int  $callerAttempt  which entry into the parking place this child serves; recorded
     *                              so a second attempt cannot silently reuse the first child
     */
    public function __construct(
        public string $sagaClass,
        public string $childId,
        public object $subject,
        public string $callerClass,
        public string $callerId,
        public string $callerTransition,
        public int $callerAttempt,
    ) {}
}
