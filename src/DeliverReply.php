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
    /** @param class-string<Saga> $callerClass */
    public function __construct(
        public string $callerClass,
        public string $callerId,
        public object $payload,
    ) {}
}
