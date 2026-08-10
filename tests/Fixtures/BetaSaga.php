<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Fixtures;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;
use Techork\Saga\Saga;

/**
 * Second saga over the SAME subject class as AlphaSaga. Resolving a workflow by
 * subject alone cannot tell these apart; resolving by saga FQCN can.
 */
final class BetaSaga implements Saga
{

    public function definition(): Definition
    {
        return new Definition(['x', 'y'], [new Transition('beta', 'x', 'y')], ['x']);
    }
}
