<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel\Fixtures;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;
use Techork\Saga\Saga;

/**
 * Named saga fixture — the Laravel job resolves sagas from the container by
 * FQCN, so these tests cannot use an anonymous class.
 *
 * fork: start -> [a, b];  reserve_stock: a -> a_done;  charge_card: b -> b_done;
 * ship: [a_done, b_done] -> done
 */
final class OrderSaga implements Saga
{

    public function definition(): Definition
    {
        return new Definition(
            ['start', 'a', 'b', 'a_done', 'b_done', 'done'],
            [
                new Transition('fork', 'start', ['a', 'b']),
                new Transition('reserve_stock', 'a', 'a_done'),
                new Transition('charge_card', 'b', 'b_done'),
                new Transition('ship', ['a_done', 'b_done'], 'done'),
            ],
            ['start'],
        );
    }
}
