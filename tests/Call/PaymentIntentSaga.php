<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Call;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;
use Techork\Saga\Saga;
use Techork\Saga\Signal;

/**
 * The callee, and it has no idea it is one. No Call, no caller field, no reference
 * to CheckoutSaga anywhere — it can be started directly, by a subscription
 * renewal, or by an operator, and behaves the same in all four.
 *
 * It reports nothing and answers nobody. Its result is its subject, and it
 * delivers that by ending: `authorized` and `failed` are both terminal, and
 * whoever launched it reads the subject out of the finished row.
 */
final class PaymentIntentSaga implements Saga
{
    public function definition(): Definition
    {
        return new Definition(
            ['new', 'awaiting_challenge', 'authorized', 'failed'],
            [
                new Transition('create', 'new', 'awaiting_challenge'),
                new Signal('challenge_passed', 'awaiting_challenge', 'authorized', awaits: ChallengePassed::class),
                new Signal('challenge_failed', 'awaiting_challenge', 'failed', awaits: ChallengeFailed::class),
            ],
            ['new'],
        );
    }
}
