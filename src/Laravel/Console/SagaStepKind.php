<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel\Console;

/**
 * Which of the three kinds of edge a wizard step is.
 *
 * There is no hierarchy behind this on purpose. Only the renderer branches —
 * `Transition` becomes one constructor call, `Signal` needs an awaited type,
 * `Call` needs a child saga and a subject mapping — and a `SagaStepInterface`
 * with three implementations would turn decoding one JSON object into a
 * polymorphic dispatch problem in exchange for nothing.
 */
enum SagaStepKind: string
{
    case Transition = 'transition';
    case Signal = 'signal';
    case Call = 'call';
}
