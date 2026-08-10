<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel\Fixtures;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;
use Techork\Saga\Saga;
use Techork\Saga\SagaRouting;

final class RoutedSaga implements Saga, SagaRouting
{

    public function definition(): Definition
    {
        return new Definition(['a', 'b'], [new Transition('go', 'a', 'b')], ['a']);
    }

    public function connection(): ?string
    {
        return 'redis-long';
    }

    public function queue(): ?string
    {
        return 'shipping';
    }
}
