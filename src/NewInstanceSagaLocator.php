<?php

declare(strict_types=1);

namespace Techork\Saga;

use Throwable;

use function class_exists;
use function is_subclass_of;

/**
 * {@see SagaLocator} that simply constructs the class.
 *
 * The default, and correct whenever sagas are what they usually are: objects
 * whose only content is a {@see Saga::definition()}. A saga with constructor
 * dependencies needs a container-backed locator instead — see the Laravel
 * service provider.
 */
final class NewInstanceSagaLocator implements SagaLocator
{
    public function get(string $sagaClass): Saga
    {
        if (! class_exists($sagaClass) || ! is_subclass_of($sagaClass, Saga::class)) {
            throw new SagaException("'$sagaClass' is not a ".Saga::class.'.');
        }

        try {
            return new $sagaClass();
        } catch (Throwable $e) {
            throw new SagaException("Saga '$sagaClass' cannot be constructed without arguments. Bind a "
                . SagaLocator::class.' that resolves it from your container.', 0, $e);
        }
    }
}
