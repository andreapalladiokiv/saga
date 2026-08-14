<?php

declare(strict_types=1);

namespace Techork\Saga;

/**
 * Hand something to a child a {@see Call} launched.
 *
 * The mirror of {@see DeliverReply}: that one carries an answer up to the caller,
 * this one carries an instruction down to the child.
 *
 * @internal
 */
final readonly class DeliverMessage
{
    /** @param class-string<Saga> $childClass */
    public function __construct(
        public string $childClass,
        public string $childId,
        public object $payload,
    ) {}
}
