<?php

declare(strict_types=1);

namespace Techork\Saga;

/**
 * Turns a saga FQCN into a saga.
 *
 * Needed because {@see Call} names the saga to run, and {@see SagaRunner::reply()}
 * names the saga to answer, as class strings — while {@see SagaRunner::start()}
 * and {@see SagaRunner::signal()} take instances. Everywhere else the caller
 * already holds the instance, so this only exists for the two paths the runner
 * drives on its own.
 *
 * Bind a container-backed one in an application whose sagas take constructor
 * dependencies; {@see NewInstanceSagaLocator} covers the common case where they
 * do not.
 */
interface SagaLocator
{
    /**
     * @param  class-string<Saga>  $sagaClass
     *
     * @throws SagaException when the class cannot be resolved to a saga
     */
    public function get(string $sagaClass): Saga;
}
