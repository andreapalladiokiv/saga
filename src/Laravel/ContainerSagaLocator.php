<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel;

use Illuminate\Contracts\Container\Container;
use Throwable;
use Techork\Saga\Saga;
use Techork\Saga\SagaException;
use Techork\Saga\SagaLocator;

use function is_subclass_of;

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
        if (! is_a($sagaClass, Saga::class, true)) {
            throw new SagaException("'$sagaClass' is not a ".Saga::class.'.');
        }

        try {
            return $this->container->make($sagaClass);
        } catch (Throwable $e) {
            throw new SagaException("Saga '$sagaClass' could not be resolved from the container.", 0, $e);
        }
    }
}
