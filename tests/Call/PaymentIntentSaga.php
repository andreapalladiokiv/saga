<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Call;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;
use Techork\Saga\Saga;
use Techork\Saga\Signal;

/**
 * The callee, and it has no idea it is one. No Call, no caller field, no
 * reference to CheckoutSaga anywhere — it can be started directly, by a
 * subscription renewal, or by an operator, and behaves the same in all four.
 *
 * It answers with {@see \Techork\Saga\SagaRunner::reply()}, which can only ever
 * reach whoever called it.
 */
final class PaymentIntentSaga implements Saga
{
    public function definition(): Definition
    {
        return new Definition(
            ['new', 'awaiting_challenge', 'authorized', 'failed', 'captured'],
            [
                new Transition('create', 'new', 'awaiting_challenge'),
                new Signal('challenge_passed', 'awaiting_challenge', 'authorized', awaits: ChallengePassed::class),
                new Signal('challenge_failed', 'awaiting_challenge', 'failed', awaits: ChallengeFailed::class),

                // The intent answers when it is authorized and then stays alive,
                // waiting to be told whether to capture. That instruction comes
                // from its caller through SagaRunner::tell().
                new Signal('capture', 'authorized', 'captured', awaits: CaptureRequested::class),
            ],
            ['new'],
        );
    }
}
