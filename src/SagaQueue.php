<?php

declare(strict_types=1);

namespace Techork\Saga;

/**
 * Hand-off between transitions.
 *
 * The runner pushes a message with the saga FQCN, saga id and the name of
 * the transition to execute next. A worker consumes the message and calls
 * {@see SagaRunner::run()}.
 */
interface SagaQueue
{
    /** @param class-string<Saga> $sagaClass */
    public function push(string $sagaClass, string $sagaId, string $transition): void;
}
