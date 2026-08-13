<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel;

use Illuminate\Contracts\Container\Container;
use Throwable;
use Techork\Saga\Saga;
use Techork\Saga\SagaException;
use Techork\Saga\SagaLocator;

/**
 * {@see SagaLocator} backed by the Laravel container, so a saga may take
 * constructor dependencies like anything else the application resolves.
 *
 * This is what {@see \Techork\Saga\Call} uses to obtain the saga it names, and
 * what {@see \Techork\Saga\SagaRunner::reply()} uses to obtain the caller.
 */
final readonly class ContainerSagaLocator implements SagaLocator
{
    public function __construct(private Container $container) {}

    public function get(string $sagaClass): Saga
    {
        try {
            // Deliberately untyped: the container's generic stub promises a Saga
            // back, but a binding is arbitrary application code and a wrong one
            // must surface as a SagaException rather than a raw TypeError, which
            // the queue layer would read as a business failure and compensate.
            /** @var mixed $saga */
            $saga = $this->container->make($sagaClass);
        } catch (Throwable $e) {
            throw new SagaException("Saga '$sagaClass' could not be resolved from the container.", 0, $e);
        }

        // Checked on what came back rather than on the class name: the name is
        // already typed, while a container binding can return anything at all.
        if (! $saga instanceof Saga) {
            throw new SagaException("The container resolved '$sagaClass' to something that is not a ".Saga::class.'.');
        }

        return $saga;
    }
}
