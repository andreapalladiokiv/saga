<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Fixtures;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;
use Techork\Saga\Saga;

/** Distinct named saga over the shared TestSubject — see BetaSaga. */
final class AlphaSaga implements Saga
{

    public function definition(): Definition
    {
        return new Definition(['a', 'b'], [new Transition('alpha', 'a', 'b')], ['a']);
    }
}
