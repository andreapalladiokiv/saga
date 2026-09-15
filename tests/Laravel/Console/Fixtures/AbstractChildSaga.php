<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel\Console\Fixtures;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;
use Techork\Saga\Saga;

/**
 * A child saga nobody can build.
 *
 * A `Call` holds the saga it runs as an object, so an abstract one is a step
 * that can never be constructed — not now, and not after the author fills in
 * the helper either. The generator refuses it instead of writing a file that
 * would only fail once something ran it.
 */
abstract class AbstractChildSaga implements Saga
{
    public function definition(): Definition
    {
        return new Definition(['idle', 'done'], [new Transition('finish', 'idle', 'done')], ['idle']);
    }
}
