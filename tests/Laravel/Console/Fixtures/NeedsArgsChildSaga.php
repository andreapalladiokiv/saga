<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel\Console\Fixtures;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;
use Techork\Saga\Saga;

/**
 * A child saga that cannot be built where it is named.
 *
 * A saga an application wires — with a gateway, a clock, a repository — is the
 * ordinary case, not the exception, and it is the reason a generated Call
 * resolves its child through a helper the author fills in instead of writing
 * `new`. That helper is a method rather than a constructor parameter on
 * purpose: a parameter would sit in the region the generator rewrites, so
 * adding a second Call would regenerate it and take the author's wiring with it.
 */
final class NeedsArgsChildSaga implements Saga
{
    public function __construct(public readonly string $gateway) {}

    public function definition(): Definition
    {
        return new Definition(['idle', 'done'], [new Transition('finish', 'idle', 'done')], ['idle']);
    }
}
