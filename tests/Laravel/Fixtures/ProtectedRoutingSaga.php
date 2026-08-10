<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel\Fixtures;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;
use Techork\Saga\Saga;

/**
 * Declares routing the old, unenforced way — a non-public property. Reading it
 * with `??` silently yielded null, so the saga went to the default queue.
 */
final class ProtectedRoutingSaga implements Saga
{
    protected string $queue = 'shipping';


    public function definition(): Definition
    {
        return new Definition(['a', 'b'], [new Transition('go', 'a', 'b')], ['a']);
    }
}
