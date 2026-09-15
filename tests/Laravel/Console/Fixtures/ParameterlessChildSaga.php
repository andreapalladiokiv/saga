<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel\Console\Fixtures;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;
use Techork\Saga\Saga;

/**
 * A child saga a generated Call can build where it names it.
 *
 * Its constructor takes nothing, which is the whole of what makes it
 * interesting: {@see \Techork\Saga\Laravel\Console\SagaScaffolder} writes
 * `new ParameterlessChildSaga()` inline for this one and a helper for the
 * others, and the two branches are what the scaffolder's Call tests pin.
 */
final class ParameterlessChildSaga implements Saga
{
    public function definition(): Definition
    {
        return new Definition(['idle', 'done'], [new Transition('finish', 'idle', 'done')], ['idle']);
    }
}
