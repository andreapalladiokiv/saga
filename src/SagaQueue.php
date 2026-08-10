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
    /**
     * @param  class-string<Saga>  $sagaClass
     * @param  non-negative-int  $delaySeconds  earliest time the step may run, relative to now.
     *                                          0 means "as soon as a worker is free". Implementations
     *                                          that cannot delay may ignore it, but then a saga cannot
     *                                          express deadlines or back off from a contended step.
     */
    public function push(string $sagaClass, string $sagaId, string $transition, int $delaySeconds = 0): void;
}
