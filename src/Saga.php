<?php

declare(strict_types=1);

namespace Techork\Saga;

use Symfony\Component\Workflow\Definition;

/**
 * User-defined saga.
 *
 * A saga is defined by a Symfony Workflow {@see Definition}; behaviour
 * (actions, guards, compensations) is attached via the application's event
 * dispatcher in {@see Saga::subscribe()}:
 *
 *   workflow.<FQCN>.guard.<transition>       — block a transition
 *   workflow.<FQCN>.transition.<transition>  — action (runs during apply())
 *   saga.<FQCN>.compensate.<transition>      — compensation on failure
 *
 * The subject is opaque to the saga library: {@see SagaRunner} persists it
 * via PHP's native `serialize()` between transitions and hands the same
 * instance back to listeners. Any subject is supported as long as it's
 * serializable — i.e. a plain DTO without closures, resources, or other
 * unserializable references. Listeners receive the subject via
 * {@see \Symfony\Component\Workflow\Event\TransitionEvent::getSubject()}.
 */
interface Saga
{
    public function definition(): Definition;

}
