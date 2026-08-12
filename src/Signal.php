<?php

declare(strict_types=1);

namespace Techork\Saga;

use Symfony\Component\Workflow\Arc;
use Symfony\Component\Workflow\Event\Event;
use Symfony\Component\Workflow\Transition;

use function get_debug_type;
use function sprintf;

/**
 * A transition the runner never fires on its own.
 *
 * Ordinary transitions are queued as soon as the marking and the guards allow.
 * A Signal is not: the only thing that fires it is {@see SagaRunner::signal()},
 * called from outside — a webhook, a scheduler, an operator. That single rule is
 * the whole mechanism, and the rest follows from it:
 *
 *  - The place a Signal leaves is where the saga PARKS. Nothing declares that
 *    place as special; it is a parking place because its way out is a Signal.
 *  - A saga is therefore in one of three states, all derived from the definition
 *    and the marking with nothing extra persisted: moving (something enabled is
 *    not a Signal), parked (everything enabled is a Signal), or stalled (nothing
 *    is enabled at all — a real anomaly rather than the normal shape of waiting).
 *  - Mixed exits need no new concept. An `expire` out of the same place is an
 *    ordinary guarded transition, so the runner does queue it and the guard
 *    decides whether the deadline has passed.
 *
 * It extends Transition on purpose rather than being a new kind of node. Symfony's
 * own dumpers — Graphviz, PlantUML, Mermaid — walk
 * `Definition::getTransitions()`, so a Signal is rendered in the diagram like any
 * other edge, and its guards are the ordinary
 * `workflow.<FQCN>.guard.<name>` listeners. A parallel list of signals declared
 * beside the definition would be invisible to both.
 *
 * Deliberately absent: a timeout. Expiring is not a property of waiting — it is
 * an ordinary guarded transition out of the same place, which the graph already
 * expresses. And a transformation hook: the payload is folded into the subject by
 * the Signal's own transition listener, like every other action.
 *
 * Not final, and only so that {@see Call} can be a Signal: a call is a wait whose
 * answer happens to come from a saga the runner started for it. Everything that
 * treats a Signal as "the runner never fires this" and "signal() is the only way
 * in" is then true of a Call for free. Do not subclass it for anything else —
 * every such rule keys on `instanceof Signal`.
 */
class Signal extends Transition
{
    /**
     * @param  string|string[]|Arc[]  $froms  where the saga parks
     * @param  string|string[]|Arc[]  $tos    where it goes once the signal arrives
     * @param  class-string  $awaits  the payload type this signal accepts
     */
    public function __construct(
        string $name,
        string|array $froms,
        string|array $tos,
        public readonly string $awaits,
    ) {
        parent::__construct($name, $froms, $tos);
    }

    /**
     * Whether this signal is the one carrying $payload.
     *
     * `instanceof`, not an exact class match, so a payload may be specialised —
     * an `ApplePayReceived extends PaymentReceived` still satisfies a signal
     * awaiting the base type.
     */
    public function accepts(object $payload): bool
    {
        return $payload instanceof $this->awaits;
    }

    /**
     * The payload a signal was fired with, typed.
     *
     * A signal's listener is an ordinary transition listener, so the payload
     * arrives in Symfony's apply context. Reading it directly means indexing an
     * `array<mixed>` by a string key, which no static analyser can check and
     * which fails at runtime with `undefined array key` on a typo. This narrows
     * it instead:
     *
     *     $payment = Signal::payload($event, PaymentReceived::class);
     *     $payment->card;      // typed, checked
     *
     * The union of event types is because every event Symfony dispatches during
     * an apply carries the context — `transition` and also `leave`, `enter`,
     * `entered`, `completed` and `announce` — but the trait providing
     * `getContext()` is marked `@internal` and there is no shared interface to
     * hint. GuardEvent is deliberately absent: guards run before the context
     * exists, which is why a guard can never be the thing that inspects a
     * signal's data.
     *
     * @template T of object
     *
     * @param  Event<T>  $event
     * @param  class-string<T>  $expected
     * @return T
     *
     * @throws SagaException when the transition was not fired by
     *                       {@see SagaRunner::signal()}, or carried another type
     */
    public static function payload(Event $event, string $expected): object
    {
        $key = SagaRunner::SIGNAL_CONTEXT_KEY;
        $context = method_exists($event, 'getContext') ? $event->getContext() : [];

        $payload = $context[$key] ?? throw new SagaException(sprintf(
            "Transition '%s' carries no signal payload. Either it is not a %s, or it was fired by "
            . 'run() rather than signal().',
            $event->getTransition()?->getName() ?? '?',
            self::class,
        ));

        if (! $payload instanceof $expected) {
            throw new SagaException(sprintf(
                "Transition '%s' was signalled with %s, not %s.",
                $event->getTransition()?->getName() ?? '?',
                get_debug_type($payload),
                $expected,
            ));
        }

        return $payload;
    }
}
