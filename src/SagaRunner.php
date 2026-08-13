<?php

declare(strict_types=1);

namespace Techork\Saga;

use Symfony\Component\Workflow\Event\Event;
use Symfony\Component\Workflow\Exception\InvalidArgumentException as WorkflowInvalidArgumentException;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Techork\Saga\Event\CompensateEvent;
use Throwable;

use const JSON_THROW_ON_ERROR;

use function array_diff;
use function array_fill_keys;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function json_encode;
use function method_exists;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Drives a Symfony Workflow saga: one transition per invocation.
 *
 * The runner adds on top of Symfony Workflow:
 *  - persistence of marking + subject + history
 *  - queued hand-off between transitions
 *  - typed subjects: the application passes a pre-built subject to
 *    {@see start()}; the runner persists it via PHP `serialize()` between
 *    transitions and hands the same instance back to listeners. Subjects
 *    must be plain serializable DTOs (no closures / resources).
 *
 * Workflow resolution goes through a {@see Registry} (Symfony's
 * {@see Registry}) populated at boot time by
 * the application. Each registered workflow is paired with an
 * {@see \Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy}
 * that matches the saga's subject class, so `$registry->get($subject)`
 * returns the right workflow without the runner needing to know saga types.
 *
 * Marking is stored externally via {@see SagaMarkingStore} so subjects stay
 * pure DTOs. The runner binds marking onto the store before each `apply()`
 * and reads it back to persist.
 *
 * Concurrency: every public entry point runs inside a {@see SagaLock} scoped
 * to the saga id, so one saga is only ever touched by one worker at a time.
 * The lock is the mechanism that prevents the race; the optimistic-lock check
 * in the repository stays as a backstop for a lock that expired or was never
 * really shared (a misconfigured cache store, or {@see InProcessSagaLock} used
 * across several workers). Because the subject is a single mutable object
 * persisted whole, this necessarily interleaves the branches of a fork rather
 * than overlapping them — see {@see SagaLock} for why that is the right trade
 * with this subject model.
 *
 * Compensation is NOT automatic — when a transition throws, the exception
 * bubbles up to the caller (e.g. {@see \Techork\Saga\Laravel\SagaStepJob}).
 * The caller decides when to compensate by invoking
 * {@see compensateAndDelete()} (typically from a Laravel job's `failed()`
 * callback, after all retries are exhausted).
 */
final readonly class SagaRunner
{
    /**
     * Appended to `history` when a rollback did not complete.
     *
     * The row has to survive a failed compensation — it is the only record of
     * what is still un-undone — but a surviving row is otherwise
     * indistinguishable from a live saga, so the leftover job for the step that
     * threw would pass can() and run the action again. That is how a refunded
     * order gets charged a second time and then ships. Journalling it into the
     * history column the row already has needs no schema change.
     */
    public const ROLLBACK_FAILED = '!saga:rollback-failed';

    /**
     * Where {@see signal()} puts the payload in Symfony's apply context, so the
     * signal's own transition listener can read it with
     * `$event->getContext()[SagaRunner::SIGNAL_CONTEXT_KEY]`.
     */
    public const SIGNAL_CONTEXT_KEY = 'saga.signal';

    /**
     * Where every apply puts the saga id, so a listener can read it with
     * {@see sagaId()}.
     *
     * Symfony's Event exposes the subject, the marking, the transition and the
     * workflow name — which is the saga FQCN, the same for every instance. The
     * id is the one thing a listener cannot otherwise reach, and it is what a
     * step needs to name anything outside itself: a child saga's id, a log line,
     * an idempotency key at a payment provider. Without it the only way to know
     * which saga you are is to copy the id into the subject at start() and hope
     * every caller remembers.
     */
    public const SAGA_ID_CONTEXT_KEY = 'saga.id';

    /**
     * Where every apply puts the step's {@see SagaOutbox}, so {@see reply()} can
     * reach it. A per-step object: a step that throws never persists, and its
     * outbox dies with it, so nothing it asked for happens.
     */
    public const OUTBOX_CONTEXT_KEY = 'saga.outbox';

    /**
     * Where the apply context carries who called this saga, for {@see reply()}.
     *
     * Read out of the row's own history rather than passed around: a child is
     * signalled by webhooks and workers that know nothing about its caller.
     */
    private const CALLER_CONTEXT_KEY = 'saga.caller';

    /**
     * Where the apply context carries the child ids this saga has handed out, for
     * {@see childId()}.
     *
     * A generated id has to reach the steps that come after the launch — the one
     * that captures the payment, the one that answers a webhook about it — and
     * that is a step boundary, which is a process boundary. The runner already
     * writes the id down when it hands it out; this passes the record along rather
     * than making every caller keep its own copy of what the engine knows.
     */
    private const CHILD_CONTEXT_KEY = 'saga.children';

    /**
     * Journalled into `history` at birth when a {@see Call} started this saga,
     * carrying the caller's class, id and the Call's name.
     *
     * The row has to remember its caller — the answer arrives a park later, in
     * another process — and history is a column that already exists and already
     * reserves this prefix. Deriving it from the child's id instead would make
     * the id format load-bearing, and ids are the user's in every other case.
     */
    private const CALLER = '!saga:caller:';

    /**
     * Journalled into the CALLER's `history` when a {@see Call} launches a child,
     * recording which id that Call's attempt was given.
     *
     * Written because a {@see Call::$id} rule generates: uuid7, a provider's
     * reference. A generated value cannot be recomputed, so recovery that tried
     * to would never recognise the child it was looking for and would create
     * another one every sweep.
     *
     * Which makes the distinction the rule has to respect a matter of attempts.
     * A TECHNICAL retry — a redelivered job, a sweep after a lost hand-off, a
     * crash between the commit and the launch — is the same wait being redriven,
     * so it must reuse the same child. A BUSINESS retry — the intent declined and
     * the saga decided to pay again — re-enters the parking place, so it is a new
     * wait and must have a new child, or the second attempt collides with the
     * first at the provider. The engine already tells them apart: a technical
     * retry leaves history untouched and so keeps the same attempt number, while
     * a business retry adds to it. So the id rule is called exactly once per
     * attempt and the answer kept here, keyed by the Call and that number.
     *
     * In `history` rather than a column of its own: the row already carries this
     * column, the '!saga:' prefix is already reserved for the runner's own
     * journal, and the write lands in the same save as the step that decided to
     * launch — so a step that never commits records nothing.
     */
    private const CHILD = '!saga:child:';

    public function __construct(
        private SagaStateRepository      $repository,
        private SagaQueue                $queue,
        private EventDispatcherInterface $dispatcher,
        private Registry                 $workflows,
        private SagaMarkingStore         $markingStore,
        private SagaLock                 $lock,
        private SagaLocator              $sagas = new NewInstanceSagaLocator(),
    ) {}

    /**
     * Bootstraps a new saga from an application-built subject.
     *
     * The caller fully constructs the subject; the runner only manages
     * persistence + marking and hands the same instance to listeners on
     * subsequent transitions.
     */
    public function start(Saga $saga, string $sagaId, object $subject): SagaState
    {
        return $this->startSeeded($saga, $sagaId, $subject, []);
    }

    /**
     * start(), with a history the runner seeds itself.
     *
     * The only seed is the {@see CALLER} marker, written when a {@see Call}
     * launches a child so the child's row remembers who to answer.
     *
     * @param  list<string>  $history
     */
    private function startSeeded(Saga $saga, string $sagaId, object $subject, array $history): SagaState
    {
        /** @var array{SagaState, list<string>, SagaOutbox} $result */
        $result = $this->lock->withLock(
            $sagaId,
            fn(): array => $this->startExclusively($saga, $sagaId, $subject, $history),
        );

        [$state, $dispatch, $outbox] = $result;
        $this->dispatch($saga, $sagaId, $dispatch);
        $this->perform($outbox);

        return $state;
    }

    /**
     * @param  list<string>  $history
     * @return array{SagaState, list<string>, SagaOutbox} state, what to enqueue, what to do to other sagas
     */
    private function startExclusively(Saga $saga, string $sagaId, object $subject, array $history): array
    {
        // One source of truth: the workflow the registry will actually apply,
        // not $saga->definition(), which may have drifted from it.
        $workflow = $this->workflowFor($saga, $subject, $sagaId);

        $initial = $workflow->getDefinition()->getInitialPlaces();
        if ($initial === []) {
            throw new SagaException('Workflow definition has no initial places.');
        }

        $marking = new Marking(array_fill_keys($initial, 1));
        $this->markingStore->setMarking($subject, $marking);

        $this->assertTransitionNamesAreUnique($workflow, $saga);

        $enabledTransitions = $workflow->getEnabledTransitions($subject);
        $enabled = $this->namesOf($this->withoutSignals($enabledTransitions));

        // A saga may legitimately start PARKED — its way out is a Signal, or a
        // guard is holding it — so an empty dispatch list is not by itself an
        // error. It is only an error when nothing could EVER fire from here.
        if ($enabledTransitions === [] && !$this->hasOutgoingTransitions($workflow, $marking)) {
            $sagaClass = $saga::class;

            throw new SagaException("Saga '$sagaId' ($sagaClass) cannot start: its initial marking has no outgoing transitions at all, "
                . 'so nothing can ever fire. This is a definition bug.');
        }

        // A saga may be born already parked on a Call — its first place has one
        // leaving it — in which case the child is launched here rather than by a
        // later step. Collected BEFORE the row is written, so a saga the runner
        // refuses leaves nothing behind.
        $outbox = new SagaOutbox();
        $journal = $this->collectLaunches(
            $saga, $sagaId, $subject, $workflow, $enabledTransitions, $initial, $history, $outbox,
        );

        $state = new SagaState(
            $sagaId,
            $this->markingToArray($marking),
            $subject,
            [...$history, ...$journal],
            version: 1,
        );
        $this->repository->save($state);

        return [$state, $enabled, $outbox];
    }

    /**
     * Enqueues follow-up steps, deliberately OUTSIDE the saga lock.
     *
     * An inline dispatcher — Laravel's `sync` connection, `Bus::dispatchSync`,
     * a test double — executes the pushed job during push(), which re-enters
     * {@see run()} for the same saga id. Pushing while still holding the lock
     * would therefore deadlock or, with {@see InProcessSagaLock}, throw; and
     * because that throw is not a {@see SagaConcurrencyException}, the queue
     * layer would treat a healthy saga as a failed one and compensate it.
     *
     * @param  list<string>  $transitions
     */
    private function dispatch(Saga $saga, string $sagaId, array $transitions): void
    {
        foreach ($transitions as $name) {
            $this->queue->push($saga::class, $sagaId, $name);
        }
    }

    /**
     * Executes one queued transition. Symfony Workflow fires the standard
     * `workflow.*` events during apply(); user action listeners run there.
     *
     * After applying the transition the runner inspects the new marking and
     * decides what to do next:
     *
     *   - One or more transitions enabled → queue all of them. A multi-target
     *     transition (`to=['a', 'b']`) puts a token in each `to`-place, so
     *     several transitions can become enabled at once over disjoint
     *     `from`-sets — that's a Petri-net parallel fork. A branch completing
     *     later re-queues its siblings, which is wasteful but safe: the saga
     *     lock serialises the duplicate and the can() check above turns it into
     *     an immediate return.
     *   - No transitions enabled but at least one structurally outgoing
     *     transition exists from the new marking → the saga is waiting on
     *     external state (a guard that will pass once the world changes).
     *     State is preserved and nothing is queued; an external caller signals
     *     run() once a guard passes.
     *   - No transitions enabled and no outgoing transitions → marking is
     *     structurally terminal. State is deleted (saga complete).
     *
     * Transition actions must still be idempotent: no queue offers
     * exactly-once delivery, so a redelivered job can re-run a step.
     *
     * On failure the exception is NOT caught — it bubbles up so the queue
     * layer can apply its retry policy. A {@see SagaConcurrencyException}
     * raised by the repository means only that the persist lost a race; the
     * queue layer must retry the step, never compensate. Call
     * {@see compensateAndDelete()} when a genuine step failure has exhausted
     * its retries.
     */
    public function run(Saga $saga, string $sagaId, string $transition): void
    {
        /** @var array{bool, list<string>, SagaOutbox} $result */
        $result = $this->lock->withLock(
            $sagaId,
            fn(): array => $this->advanceExclusively($saga, $sagaId, $transition),
        );

        [, $dispatch, $outbox] = $result;
        $this->dispatch($saga, $sagaId, $dispatch);
        $this->perform($outbox);
    }

    /**
     * Fires the {@see Signal} that accepts $payload, unparking the saga.
     *
     * A parked saga is one whose only enabled transitions are Signals, which the
     * runner never queues by itself. This is the way in. The payload reaches the
     * signal's transition listener as Symfony's apply context, under
     * {@see SIGNAL_CONTEXT_KEY} — the same channel Symfony uses for per-apply
     * data — and that listener is where it gets folded into the subject if it
     * needs to outlive the step.
     *
     * The signal is matched from the marking alone: of the Signals currently
     * enabled, exactly one must accept the payload. Zero is an error naming what
     * the saga is actually waiting for, and more than one is an ambiguous
     * definition. Neither is silently guessed at.
     *
     * Runs under the same {@see SagaLock} and the same optimistic-lock save as
     * every other write, so two simultaneous signals end with one applied.
     *
     * A saga that no longer exists is not an error — a signal may legitimately
     * arrive late.
     *
     * @throws SagaException when nothing enabled accepts the payload, when more
     *                       than one Signal does, or when the rollback is
     *                       incomplete
     */
    public function signal(Saga $saga, string $sagaId, object $payload): SignalOutcome
    {
        /** @var array{SignalOutcome, list<string>, SagaOutbox} $result */
        $result = $this->lock->withLock(
            $sagaId,
            fn(): array => $this->signalExclusively($saga, $sagaId, $payload),
        );

        [$outcome, $dispatch, $outbox] = $result;
        $this->dispatch($saga, $sagaId, $dispatch);
        $this->perform($outbox);

        return $outcome;
    }

    /** @return array{SignalOutcome, list<string>, SagaOutbox} */
    private function signalExclusively(Saga $saga, string $sagaId, object $payload): array
    {
        $state = $this->repository->load($sagaId);
        if ($state === null) {
            return [SignalOutcome::NotFound, [], new SagaOutbox()];
        }

        $this->assertRollbackIsNotIncomplete($state, $sagaId);
        $this->assertMarkingIsNotEmpty($state, $sagaId);

        $subject = $state->subject;
        $workflow = $this->workflowFor($saga, $subject, $sagaId);
        $this->assertMarkingStillFits($workflow, $state, $sagaId);

        $this->markingStore->setMarking($subject, new Marking($state->marking));

        $signals = $this->enabledSignals($workflow, $subject);
        $matching = array_values(array_filter(
            $signals,
            static fn(Signal $signal): bool => $signal->accepts($payload),
        ));

        if ($matching === []) {
            $awaited = array_map(
                static fn(Signal $signal): string => $signal->getName().' awaits '.$signal->awaits,
                $signals,
            );
            $payloadClass = $payload::class;

            throw new SagaNotWaitingException("Saga '$sagaId' is not waiting for a $payloadClass. "
                . ($awaited === []
                    ? 'It has no signal enabled at all — it is either moving or stalled, not parked.'
                    : 'Enabled signals: '.implode('; ', $awaited).'.'));
        }

        if (count($matching) > 1) {
            $names = implode(', ', array_map(static fn(Signal $s): string => $s->getName(), $matching));
            $payloadClass = $payload::class;

            throw new SagaException("Saga '$sagaId' has more than one signal accepting a $payloadClass "
                . "($names). Narrow their awaited types, or guard all but one of them.");
        }

        // From here it is an ordinary apply, with the payload riding Symfony's
        // own context channel.
        [, $dispatch, $outbox] = $this->applyAndPersist(
            $saga,
            $sagaId,
            $state,
            $workflow,
            $subject,
            $matching[0]->getName(),
            [self::SIGNAL_CONTEXT_KEY => $payload],
        );

        return [SignalOutcome::Applied, $dispatch, $outbox];
    }

    /**
     * The id of the saga this event belongs to.
     *
     * The counterpart of {@see Signal::payload()}: same channel, same reason —
     * reading the context directly means indexing an `array<mixed>` by a string
     * key that no static analyser can check.
     *
     *     $childId = SagaRunner::sagaId($event) . ':intent';
     *
     * @param  Event<object>  $event
     *
     * @throws SagaException when the event did not come from an apply this
     *                       runner drove
     */
    public static function sagaId(Event $event): string
    {
        $context = method_exists($event, 'getContext') ? $event->getContext() : [];
        $id = $context[self::SAGA_ID_CONTEXT_KEY] ?? null;

        if (!is_string($id)) {
            throw new SagaException(sprintf(
                "No saga id on the event for transition '%s'. Guards run before the context exists, "
                . 'so a guard can never ask this; and an apply() called outside %s carries nothing.',
                $event->getTransition()?->getName() ?? '?',
                self::class,
            ));
        }

        return $id;
    }

    /**
     * The child's answer to whoever called it.
     *
     * The only way a saga addresses another saga, and it can address exactly one:
     * the {@see Call} that started it. That narrowness is the whole safety
     * argument — there is no target to get wrong, no lock to take in the wrong
     * order, and no way to build a cycle. Compare a hand-written bridge, which
     * can signal anything from anywhere and deadlocks the moment two sagas reach
     * for each other.
     *
     *     SagaRunner::reply($event, new PaymentAuthorized($code));
     *
     * The answer is not sent here. It is recorded in the step's outbox and
     * delivered once this saga's lock is released, so the caller is signalled
     * with nothing held. A step that throws never persists and its answer is
     * dropped with it.
     *
     * Answering is not tied to finishing: a saga may answer at any step and carry
     * on. What the caller does with the answer is the caller's business — it
     * arrives as the Call's payload and its listener reads it with
     * {@see Signal::payload()}.
     *
     * @param  Event<object>  $event
     *
     * @throws SagaException when this saga has no caller, or the event did not
     *                       come from an apply this runner drove
     */
    public static function reply(Event $event, object $payload): void
    {
        $context = method_exists($event, 'getContext') ? $event->getContext() : [];

        $outbox = $context[self::OUTBOX_CONTEXT_KEY] ?? null;
        if (! $outbox instanceof SagaOutbox) {
            throw new SagaException(sprintf(
                "Cannot reply from transition '%s': the event did not come from an apply driven by %s.",
                $event->getTransition()?->getName() ?? '?',
                self::class,
            ));
        }

        $caller = $context[self::CALLER_CONTEXT_KEY] ?? null;
        if ($caller === null) {
            throw new SagaException(sprintf(
                "Cannot reply from transition '%s': this saga has no caller. Only a saga started by a %s "
                . 'has something to answer; one started directly has nowhere to send it.',
                $event->getTransition()?->getName() ?? '?',
                Call::class,
            ));
        }

        [$callerClass, $callerId, $callerTransition, $callerAttempt] = $caller;
        $outbox->add(new DeliverReply($callerClass, $callerId, $callerTransition, $callerAttempt, $payload));
    }

    /**
     * The id of the child a {@see Call} launched, for a step that runs after the
     * launch.
     *
     * The id is generated once, when the Call hands it out, and written down; this
     * reads that record. So the step that captures the payment, or the endpoint
     * answering a webhook about it, can name the child without the launching step
     * having copied the value into the subject on its way past.
     *
     *     $intentId = SagaRunner::childId($event, 'pay');
     *
     * The latest attempt's, because that is the one in progress: a business retry
     * hands out a new id, and the previous attempt's child is done with.
     *
     * @param  Event<object>  $event
     */
    public static function childId(Event $event, string $call): ?string
    {
        $context = method_exists($event, 'getContext') ? $event->getContext() : [];
        $children = $context[self::CHILD_CONTEXT_KEY] ?? [];

        return is_array($children) && is_string($children[$call] ?? null) ? $children[$call] : null;
    }

    /**
     * Every Call's latest child id, read out of the row's own journal.
     *
     * @return array<string, string> the Call's name => the child's id
     */
    private function childrenOf(SagaState $state): array
    {
        $children = [];

        foreach ($state->history as $entry) {
            if (! str_starts_with($entry, self::CHILD)) {
                continue;
            }

            /** @var array{string, int, string} $record */
            $record = json_decode(substr($entry, strlen(self::CHILD)), true, 512, JSON_THROW_ON_ERROR);
            $children[$record[0]] = $record[2];
        }

        return $children;
    }

    /**
     * Whether this saga was started by a {@see Call}, and so has somewhere to
     * {@see reply()}.
     *
     * A saga worth reusing is one that runs both ways — launched by a Call from
     * some larger flow, and started directly by an endpoint or an operator. Such
     * a saga has to ask, and asking by catching the exception reply() throws
     * would mean matching on a message.
     *
     *     if (SagaRunner::hasCaller($event)) {
     *         SagaRunner::reply($event, new PaymentAuthorized($code));
     *     }
     *
     * @param  Event<object>  $event
     */
    public static function hasCaller(Event $event): bool
    {
        $context = method_exists($event, 'getContext') ? $event->getContext() : [];

        return isset($context[self::CALLER_CONTEXT_KEY]);
    }

    /**
     * Runs what a step asked to have done to other sagas, with no lock held.
     *
     * Deliberately after {@see SagaLock::withLock()} returns, for the same reason
     * {@see dispatch()} is: reaching into a second saga while holding the first
     * one's lock is what deadlocks an inline queue driver, and it is what a
     * hand-written bridge cannot avoid. Here the runner owns both ends, so it can
     * choose the safe moment.
     *
     * Failures are not swallowed — a child that cannot be started, or an answer
     * that cannot be delivered, means the flow has stalled and the caller (a
     * queue job) should hear about it. The exceptions a redelivered step
     * legitimately produces are the two that mean 'already done', and those are
     * absorbed at each action.
     */
    private function perform(SagaOutbox $outbox): void
    {
        foreach ($outbox->actions() as $action) {
            if ($action instanceof LaunchChild) {
                $this->launch($action);

                continue;
            }

            $this->deliver($action);
        }
    }

    private function launch(LaunchChild $launch): void
    {
        $child = $this->sagas->get($launch->sagaClass);

        $caller = self::CALLER.json_encode([
            $launch->callerClass,
            $launch->callerId,
            $launch->callerTransition,
            $launch->callerAttempt,
        ], JSON_THROW_ON_ERROR);

        try {
            $this->startSeeded($child, $launch->childId, $launch->subject, [$caller]);
        } catch (SagaAlreadyExistsException $e) {
            $this->assertTheExistingChildIsThisOne($launch, $e);
        }
    }

    /**
     * Decides whether a launch that found the id taken is harmless.
     *
     * It is when the row is this very launch arriving twice — a redelivered step,
     * or the recovery sweep having got there first. The id is derived precisely
     * so that is a no-op rather than a second child.
     *
     * It is not when the row belongs to a different attempt of the same Call,
     * which is what a {@see Call::$id} rule that ignores its $attempt argument
     * produces. Swallowing that would be the worst possible outcome: the second
     * attempt would quietly adopt the first attempt's finished child, no launch
     * would happen, and the parent would wait for an answer nobody is going to
     * send. Nor when the row is some unrelated saga that happens to share the id.
     */
    private function assertTheExistingChildIsThisOne(LaunchChild $launch, SagaAlreadyExistsException $cause): void
    {
        $existing = $this->repository->load($launch->childId);
        $caller = $existing === null ? null : $this->callerOf($existing);

        if ($caller !== null
            && $caller[0] === $launch->callerClass
            && $caller[1] === $launch->callerId
            && $caller[2] === $launch->callerTransition
            && $caller[3] === $launch->callerAttempt
        ) {
            return;
        }

        $owner = $caller === null
            ? 'a saga that no Call started'
            : "attempt {$caller[3]} of '{$caller[2]}' in {$caller[0]}#{$caller[1]}";

        throw new SagaException("Cannot start '{$launch->childId}' for attempt {$launch->callerAttempt} of "
            . "'{$launch->callerTransition}': that id already belongs to $owner. A ".Call::class.' id rule must '
            . 'vary by its $attempt argument, or every retry reuses the first attempt\'s child and the caller '
            . 'waits for an answer that will never come.', 0, $cause);
    }

    /**
     * Hands a child's answer to the {@see Call} that started it.
     *
     * Named, not matched by type. The runner knows which Call launched this child
     * — it wrote the name into the child's history at birth — and routing by the
     * payload's type instead threw that away, with two consequences. Two Calls
     * awaiting the same type could not both exist, so N children of one kind was
     * impossible. And an answer from an attempt the caller had already abandoned
     * was applied to whatever wait was current, so a saga that gave up on a
     * deadline, retried, and then heard from the abandoned child accepted that
     * answer and moved on — while the child it was actually waiting for answered
     * into nothing.
     */
    private function deliver(DeliverReply $reply): void
    {
        $caller = $this->sagas->get($reply->callerClass);

        $dispatch = $this->lock->withLock(
            $reply->callerId,
            fn(): array => $this->answerExclusively($caller, $reply),
        );

        /** @var list<string> $dispatch */
        $this->dispatch($caller, $reply->callerId, $dispatch);
    }

    /**
     * Applies an answer to the exact Call that awaits it, or drops it.
     *
     * An answer is dropped, not refused, when the wait it belongs to is over: the
     * caller is gone, or it has left the parking place, or it has come back to
     * that place for a LATER attempt than this answer belongs to. Answers are
     * at-least-once and a child may outlive the caller's patience, so a late one
     * is the normal shape of the world rather than a fault — but it must not be
     * mistaken for the answer to the wait now in progress.
     *
     * @return list<string> transitions to enqueue
     */
    private function answerExclusively(Saga $caller, DeliverReply $reply): array
    {
        $state = $this->repository->load($reply->callerId);
        if ($state === null || in_array(self::ROLLBACK_FAILED, $state->history, true)) {
            return [];
        }

        $subject = $state->subject;
        $workflow = $this->workflowFor($caller, $subject, $reply->callerId);
        $this->assertMarkingStillFits($workflow, $state, $reply->callerId);
        $this->markingStore->setMarking($subject, new Marking($state->marking));

        $call = null;
        foreach ($workflow->getEnabledTransitions($subject) as $transition) {
            if ($transition instanceof Call && $transition->getName() === $reply->callerTransition) {
                $call = $transition;
                break;
            }
        }

        if ($call === null) {
            // The caller is not waiting on this Call any more.
            return [];
        }

        $parking = null;
        foreach ($call->getFroms() as $from) {
            if (isset($state->marking[$from])) {
                $parking = $from;
                break;
            }
        }

        if ($parking === null
            || $this->timesEntered($workflow, $state->history, $parking) !== $reply->callerAttempt
        ) {
            // A different attempt's wait. Applying this would settle the current
            // one with an answer from a child the caller has already written off.
            return [];
        }

        // Which exit of the wait this answer takes. The Call names the answer it
        // is for, so an answer of that type goes to the Call itself — by name,
        // which is what lets two Calls await the same type and what keeps a
        // stale answer off a live wait. Anything else is a different exit from
        // the same place, an ordinary Signal for 'the attempt failed', and those
        // are chosen by type as they always were.
        $transition = $call->accepts($reply->payload)
            ? $call->getName()
            : $this->signalAccepting($workflow, $subject, $reply->payload, $reply->callerId);

        if ($transition === null) {
            return [];
        }

        [, $dispatch, $outbox] = $this->applyAndPersist(
            $caller,
            $reply->callerId,
            $state,
            $workflow,
            $subject,
            $transition,
            [self::SIGNAL_CONTEXT_KEY => $reply->payload],
        );

        $this->perform($outbox);

        return $dispatch;
    }

    /**
     * The one enabled Signal that accepts $payload, for an answer the Call it came
     * from does not await.
     *
     * Null when nothing does — the caller declared no exit for this outcome, which
     * is its own graph's business — and an exception when several do, because
     * guessing between them is worse than saying so.
     */
    private function signalAccepting(
        WorkflowInterface $workflow,
        object $subject,
        object $payload,
        string $sagaId,
    ): ?string {
        $matching = array_values(array_filter(
            $this->enabledSignals($workflow, $subject),
            static fn(Signal $signal): bool => $signal->accepts($payload),
        ));

        if ($matching === []) {
            return null;
        }

        if (count($matching) > 1) {
            $names = implode(', ', array_map(static fn(Signal $s): string => $s->getName(), $matching));
            $payloadClass = $payload::class;

            throw new SagaException("Saga '$sagaId' has more than one signal accepting a $payloadClass "
                . "($names). Narrow their awaited types, or guard all but one of them.");
        }

        return $matching[0]->getName();
    }

    /**
     * Records a launch for every Call the saga has just parked on.
     *
     * A Call fires nothing by itself — it is a {@see Signal} — so what makes it
     * different is only this: entering the place it leaves starts the saga it
     * names. Guards are respected, because a Call its guard blocks is not a wait
     * the saga is actually in.
     *
     * @param  object  $subject  the LIVE subject, as the step just left it — not the
     *                            stored row, which is a step behind and, once a codec
     *                            has decoded it, a different object entirely. The step
     *                            carrying the saga into a parking place is usually the
     *                            one that obtained what the child needs.
     * @param  array<Transition>  $enabled  what is fireable from the new marking
     * @param  array<string>  $entered  the places this step just put a token in
     * @param  array<string>  $history  including the step just applied
     * @return list<string> journal entries the caller must save with the step
     */
    private function collectLaunches(
        Saga $saga,
        string $sagaId,
        object $subject,
        WorkflowInterface $workflow,
        array $enabled,
        array $entered,
        array $history,
        SagaOutbox $outbox,
    ): array {
        $journal = [];

        foreach ($enabled as $transition) {
            if (! $transition instanceof Call) {
                continue;
            }

            $parking = null;
            foreach ($transition->getFroms() as $from) {
                if (in_array($from, $entered, true)) {
                    $parking = $from;
                    break;
                }
            }

            if ($parking === null) {
                // Enabled, but the saga did not arrive here on this step — the
                // child was launched when it did. Launching again would be a
                // second child for one wait.
                continue;
            }

            $attempt = $this->timesEntered($workflow, $history, $parking);
            $childSubject = $transition->subjectFor($subject);

            $childId = $this->recordedChildId($history, $transition->getName(), $attempt);
            if ($childId === null) {
                $childId = $this->childIdFor($transition, $sagaId, $childSubject, $attempt);
                $journal[] = self::CHILD.json_encode(
                    [$transition->getName(), $attempt, $childId],
                    JSON_THROW_ON_ERROR,
                );
            }

            $outbox->add(new LaunchChild(
                $transition->runs,
                $childId,
                $childSubject,
                $saga::class,
                $sagaId,
                $transition->getName(),
                $attempt,
            ));
        }

        return $journal;
    }

    /**
     * The id a Call's attempt was already given, if it was.
     *
     * @param  array<string>  $history
     */
    private function recordedChildId(array $history, string $transition, int $attempt): ?string
    {
        foreach ($history as $entry) {
            if (! str_starts_with($entry, self::CHILD)) {
                continue;
            }

            /** @var array{string, int, string} $record */
            $record = json_decode(substr($entry, strlen(self::CHILD)), true, 512, JSON_THROW_ON_ERROR);

            if ($record[0] === $transition && $record[1] === $attempt) {
                return $record[2];
            }
        }

        return null;
    }

    /**
     * The child's id: derived, never supplied.
     *
     * Two things follow from deriving it. A parent that loops back for a second
     * attempt gets a distinct child instead of colliding with the first, because
     * the count of steps taken differs. And a launch lost between the parent's
     * commit and {@see perform()} is recoverable: {@see requeue()} recomputes the
     * same id and creates what is missing.
     */
    private static function defaultChildId(string $parentId, string $transition, int $attempt): string
    {
        return $parentId.'/'.$transition.'/'.$attempt;
    }

    /**
     * The child's id: the Call's own rule when it declares one, else the
     * runner's.
     *
     * Either way it is a pure function of things that do not change while the
     * parent is parked, which is what lets {@see missingChildren()} ask whether
     * the child exists at all.
     */
    private function childIdFor(Call $call, string $parentId, object $childSubject, int $attempt): string
    {
        return $call->idFor($childSubject, $attempt)
            ?? self::defaultChildId($parentId, $call->getName(), $attempt);
    }

    /**
     * How many times the saga has entered $place, counted from history.
     *
     * This is the attempt number, and it has to be entries into the PLACE rather
     * than steps taken: a parent that comes back for a second payment reaches the
     * parking place by a different transition than the first time, and counting
     * anything else either collides the two children or makes the id depend on
     * unrelated steps.
     *
     * Being born in a place counts as entering it. Nothing writes that to history
     * — no transition ran — so without it a saga whose very first place has a Call
     * leaving it would report attempt 0 while every other first attempt reports 1,
     * and an id rule taking $attempt would see the difference.
     *
     * @param  array<string>  $history
     * @return int<1, max>
     */
    private function timesEntered(WorkflowInterface $workflow, array $history, string $place): int
    {
        $definition = $workflow->getDefinition();

        $entering = [];
        foreach ($definition->getTransitions() as $transition) {
            if (in_array($place, $transition->getTos(), true)) {
                $entering[$transition->getName()] = true;
            }
        }

        $times = in_array($place, $definition->getInitialPlaces(), true) ? 1 : 0;
        foreach ($history as $entry) {
            if (isset($entering[$entry])) {
                $times++;
            }
        }

        return max(1, $times);
    }

    /**
     * The places a transition puts tokens in.
     *
     * @return list<string>
     */
    private function targetsOf(WorkflowInterface $workflow, string $transition): array
    {
        foreach ($workflow->getDefinition()->getTransitions() as $candidate) {
            if ($candidate->getName() === $transition) {
                return array_values($candidate->getTos());
            }
        }

        return [];
    }

    /**
     * Who called this saga, if a {@see Call} did.
     *
     * @return array{class-string<Saga>, string, string, int}|null class, id, the Call's name, the attempt
     */
    private function callerOf(SagaState $state): ?array
    {
        foreach ($state->history as $entry) {
            if (! str_starts_with($entry, self::CALLER)) {
                continue;
            }

            /** @var array{class-string<Saga>, string, string, int} $caller */
            $caller = json_decode(substr($entry, strlen(self::CALLER)), true, 512, JSON_THROW_ON_ERROR);

            return $caller;
        }

        return null;
    }

    /**
     * The Signals currently fireable — what the saga is parked on.
     *
     * @return list<Signal>
     */
    private function enabledSignals(WorkflowInterface $workflow, object $subject): array
    {
        return array_values(array_filter(
            $workflow->getEnabledTransitions($subject),
            static fn(Transition $t): bool => $t instanceof Signal,
        ));
    }

    /** @return array{bool, list<string>, SagaOutbox} applied; what to enqueue; what to do to other sagas */
    private function advanceExclusively(Saga $saga, string $sagaId, string $transition): array
    {
        $state = $this->repository->load($sagaId);
        if ($state === null) {
            // Race: saga was already completed/canceled by a concurrent step.
            // Nothing to do — silently skip rather than throw, since a signal
            // from outside may legitimately race with the forward path.
            return [false, [], new SagaOutbox()];
        }

        $this->assertRollbackIsNotIncomplete($state, $sagaId);
        $this->assertMarkingIsNotEmpty($state, $sagaId);

        $subject = $state->subject;
        $workflow = $this->workflowFor($saga, $subject, $sagaId);

        $this->assertMarkingStillFits($workflow, $state, $sagaId);
        $this->assertTransitionStillExists($workflow, $sagaId, $transition);
        $this->assertNotASignal($workflow, $sagaId, $transition);

        // Marking's constructor takes place => token count, so the counts survive
        // the round trip instead of collapsing to one token each.
        $this->markingStore->setMarking($subject, new Marking($state->marking));

        // Duplicate guard. Reached under the saga lock, so the marking was read
        // exclusively: a job redelivered after this transition was already
        // consumed genuinely finds it no longer fireable, rather than racing a
        // concurrent worker into the same apply().
        if (!$workflow->can($subject, $transition)) {
            return [false, [], new SagaOutbox()];
        }

        return $this->applyAndPersist($saga, $sagaId, $state, $workflow, $subject, $transition, []);
    }

    /**
     * Applies one transition and persists the result, returning what to queue.
     *
     * Shared by {@see run()} and {@see signal()}, so a signal goes through exactly
     * the same optimistic-lock save, terminal cleanup and fan-out as any other
     * step. The only difference is the apply context.
     *
     * @param  array<string, mixed>  $context  Symfony's per-apply context
     * @return array{bool, list<string>, SagaOutbox}
     */
    private function applyAndPersist(
        Saga $saga,
        string $sagaId,
        SagaState $state,
        WorkflowInterface $workflow,
        object $subject,
        string $transition,
        array $context,
    ): array {
        $outbox = new SagaOutbox();

        // The id, the outbox and the caller go in for every apply, not just a
        // signal's. Listed first so a caller's own context wins on a key clash.
        $applied = $workflow->apply($subject, $transition, [
            self::SAGA_ID_CONTEXT_KEY => $sagaId,
            self::OUTBOX_CONTEXT_KEY => $outbox,
            self::CALLER_CONTEXT_KEY => $this->callerOf($state),
            self::CHILD_CONTEXT_KEY => $this->childrenOf($state),
            ...$context,
        ]);

        $this->assertNothingWasStashedInTheApplyContext($applied, $sagaId, $transition);

        $newMarking = $this->markingStore->getMarking($subject);
        $history = [...$state->history, $transition];
        $enabled = $workflow->getEnabledTransitions($subject);

        // Entering a place a Call leaves is what launches that Call's saga. The
        // places just entered are the applied transition's own targets.
        $history = [...$history, ...$this->collectLaunches(
            $saga,
            $sagaId,
            $subject,
            $workflow,
            $enabled,
            $this->targetsOf($workflow, $transition),
            $history,
            $outbox,
        )];

        // Terminal: nothing enabled and nothing structurally outgoing. The saga
        // reached a place that accepts no further moves — clean up and exit.
        if ($enabled === [] && !$this->hasOutgoingTransitions($workflow, $newMarking)) {
            $this->repository->delete($sagaId);

            return [true, [], $outbox];
        }

        $this->repository->save(new SagaState(
            $state->id,
            $this->markingToArray($newMarking),
            $subject,
            $history,
            $state->version + 1,
        ));

        // Signals are excluded, and that exclusion IS the parking mechanism: a
        // saga whose only fireable transitions are Signals has nothing queued
        // against it and waits for signal() to be called from outside. Nothing is
        // persisted to express that — it is derived from the definition and the
        // marking.
        //
        // Everything else fireable is queued, including siblings of a fork that
        // are already in flight. Correctness comes from the saga lock plus the
        // can() check, which reduce a duplicate to a job that takes the lock,
        // finds its transition consumed and returns. Suppressing them would need
        // the dispatched set persisted, and would buy job count, not correctness.
        return [true, $this->namesOf($this->withoutSignals($enabled)), $outbox];
    }

    /**
     * @param  array<Transition>  $transitions
     * @return list<Transition>
     */
    private function withoutSignals(array $transitions): array
    {
        return array_values(array_filter(
            $transitions,
            static fn(Transition $t): bool => !$t instanceof Signal,
        ));
    }

    /**
     * @param  array<Transition>  $transitions
     * @return list<string>
     */
    private function namesOf(array $transitions): array
    {
        return array_values(array_unique(array_map(
            static fn(Transition $t): string => $t->getName(),
            $transitions,
        )));
    }

    /**
     * A Signal may only be fired through {@see signal()}.
     *
     * Firing one through run() would skip the payload entirely, so the listener
     * that folds the signal's data in would see an empty context — the saga would
     * advance past its own wait with nothing to show for it.
     */
    private function assertNotASignal(WorkflowInterface $workflow, string $sagaId, string $transition): void
    {
        foreach ($workflow->getDefinition()->getTransitions() as $candidate) {
            if ($candidate->getName() === $transition && $candidate instanceof Signal) {
                // Drift rather than a plain error: a deploy that turns an existing
                // transition into a Signal leaves queued jobs for it, and those
                // must not be mistaken for business failures and compensated.
                throw SagaDefinitionDriftException::firedSignalDirectly($sagaId, $transition);
            }
        }
    }

    private function assertMarkingIsNotEmpty(SagaState $state, string $sagaId): void
    {
        if ($state->marking === []) {
            // Symfony would treat this as "subject not in the workflow yet" and
            // re-seed the initial places, silently restarting the saga and
            // re-running its first action. The library never persists an empty
            // marking, so this is always corruption.
            throw new SagaException("Saga '$sagaId' has an empty marking, which the runner never writes — the row is corrupt.");
        }
    }


    /**
     * Rolls the saga back and, if every compensation succeeded, deletes it.
     *
     * Call this after all retries for a failed transition are exhausted
     * (e.g. from {@see \Techork\Saga\Laravel\SagaStepJob::failed()}).
     *
     * Compensation covers the failing transition FIRST, then every applied
     * transition in reverse. The failing one is not in `history` — history is
     * written only after a step persists, and a step that throws never does —
     * so without $failedTransition the one step guaranteed to have run, and to
     * have run only partway, would be the only one never rolled back. Pass it
     * whenever the caller knows which step failed; compensation listeners must
     * tolerate a step that in fact did nothing.
     *
     * When a compensation listener throws, the state row is NOT deleted: it
     * carries the subject and history needed to retry the rollback, which
     * deleting would destroy. Nothing marks it as needing attention — the row
     * looks like a live saga — so the errors returned here are the only signal,
     * and the caller must surface them
     * ({@see \Techork\Saga\Laravel\SagaStepJob} logs them at `critical` and
     * rethrows as {@see SagaFailedException}).
     *
     * Held under the same {@see SagaLock} as {@see run()}, so compensation
     * cannot interleave with a step still in flight on another worker, and two
     * simultaneous failures cannot dispatch the same compensation twice.
     *
     * @return Throwable[] errors thrown by individual compensation listeners,
     *                     in the order they were raised
     */
    public function compensateAndDelete(
        Saga $saga,
        string $sagaId,
        ?string $failedTransition = null,
        ?Throwable $cause = null,
    ): array {
        return $this->lock->withLock(
            $sagaId,
            fn(): array => $this->compensateExclusively($saga, $sagaId, $failedTransition, $cause),
        );
    }

    /** @return Throwable[] */
    private function compensateExclusively(
        Saga $saga,
        string $sagaId,
        ?string $failedTransition,
        ?Throwable $cause,
    ): array {
        $state = $this->repository->load($sagaId);
        if ($state === null) {
            return [];
        }

        $subject = $state->subject;
        $errors = [];
        $name = $saga::class;

        // The step that failed is undone first: it ran most recently, and it is
        // the only one whose effects are known to be partial.
        if ($failedTransition !== null) {
            $this->compensate($name, $sagaId, $failedTransition, $subject, $cause, failed: true, errors: $errors);
        }

        for ($i = count($state->history) - 1; $i >= 0; $i--) {
            // '!saga:' entries are the runner's own journal, not transitions —
            // dispatching a compensation named after one would look for a
            // listener that cannot exist.
            if (str_starts_with($state->history[$i], '!saga:')) {
                continue;
            }

            $this->compensate($name, $sagaId, $state->history[$i], $subject, $cause, failed: false, errors: $errors);
        }

        if ($errors !== []) {
            // Rollback is incomplete. Keep the row — it is the only record of
            // what still needs undoing — but mark it, or the leftover job for the
            // step that threw will pass can() and run the action again.
            $this->repository->save(new SagaState(
                $state->id,
                $state->marking,
                $subject,
                [...$state->history, self::ROLLBACK_FAILED],
                $state->version + 1,
            ));

            return $errors;
        }

        $this->repository->delete($sagaId);

        return $errors;
    }

    /**
     * Dispatches one compensation, collecting rather than propagating its
     * failure — one broken rollback must not abort the rest.
     *
     * @param  Throwable[]  $errors
     */
    private function compensate(
        string $sagaClass,
        string $sagaId,
        string $transition,
        object $subject,
        ?Throwable $cause,
        bool $failed,
        array &$errors,
    ): void {
        $event = new CompensateEvent($sagaClass, $sagaId, $transition, $subject, $cause, $failed);

        try {
            $this->dispatcher->dispatch($event, "saga.$sagaClass.compensate.$transition");
        } catch (Throwable $e) {
            $errors[] = $e;
        }
    }

    /**
     * Re-queues whatever the saga can currently fire.
     *
     * The recovery path for a lost hand-off: {@see run()} persists the new state
     * and then pushes, two steps with nothing tying them together, so a queue
     * that is briefly unreachable leaves a live row with no job against it.
     * Nothing notices on its own.
     *
     * This used to happen automatically whenever a replayed job found its
     * transition already applied — which turned every duplicate into another
     * push and made a two-branch fork grow 2^L rather than linearly. Doing it on
     * request keeps the hot path linear.
     *
     * Not to be confused with {@see signal()}, which is how a PARKED saga is
     * unblocked. This one only re-queues work that is already fireable, and it
     * excludes Signals for the same reason every other path does — they are
     * fired from outside, never by the runner.
     *
     * Safe to call on a healthy saga: a step still genuinely queued simply
     * arrives twice, and the saga lock plus the can() check reduce the duplicate
     * to an immediate return.
     *
     * @return list<string> the transitions re-queued
     */
    public function requeue(Saga $saga, string $sagaId): array
    {
        /** @var array{list<string>, SagaOutbox} $result */
        $result = $this->lock->withLock($sagaId, function () use ($saga, $sagaId): array {
            $state = $this->repository->load($sagaId);
            if ($state === null || in_array(self::ROLLBACK_FAILED, $state->history, true)) {
                // Silent rather than throwing: requeue() is a recovery sweep and
                // must be safe to run over a whole table.
                return [[], new SagaOutbox()];
            }

            $subject = $state->subject;
            $this->markingStore->setMarking($subject, new Marking($state->marking));

            $workflow = $this->workflowFor($saga, $subject, $sagaId);
            $enabled = $workflow->getEnabledTransitions($subject);

            return [
                $this->namesOf($this->withoutSignals($enabled)),
                $this->missingChildren($saga, $sagaId, $state, $workflow, $enabled),
            ];
        });

        [$dispatch, $outbox] = $result;
        $this->dispatch($saga, $sagaId, $dispatch);
        $this->perform($outbox);

        return $dispatch;
    }

    /**
     * Launches a child a parked saga is waiting for but which does not exist.
     *
     * The one hole {@see Call} leaves on its own: the parent commits, and the
     * process dies before {@see perform()} starts the child. The parent is then
     * parked on an answer nobody will ever send, and nothing is queued against it.
     *
     * It is recoverable only because the child's id is derived rather than
     * supplied — the same inputs give the same id, so 'is it there?' is a question
     * that can be asked at all. Answers are not recoverable this way: a child that
     * finished and whose answer was lost has deleted its row, so this finds
     * nothing missing. It reports a stall it cannot repair.
     *
     * @param  array<Transition>  $enabled
     */
    private function missingChildren(
        Saga $saga,
        string $sagaId,
        SagaState $state,
        WorkflowInterface $workflow,
        array $enabled,
    ): SagaOutbox {
        $outbox = new SagaOutbox();

        foreach ($enabled as $transition) {
            if (! $transition instanceof Call) {
                continue;
            }

            $parking = null;
            foreach ($transition->getFroms() as $from) {
                if (isset($state->marking[$from])) {
                    $parking = $from;
                    break;
                }
            }
            if ($parking === null) {
                continue;
            }

            $attempt = $this->timesEntered($workflow, $state->history, $parking);
            $childSubject = $transition->subjectFor($state->subject);

            // The recorded id, never a fresh one: this is the technical-retry path,
            // and minting here is what turned one payment into a payment per sweep.
            // Nothing recorded means the launch was never collected, so falling
            // back to the rule is the first mint rather than a second.
            $childId = $this->recordedChildId($state->history, $transition->getName(), $attempt)
                ?? $this->childIdFor($transition, $sagaId, $childSubject, $attempt);

            if ($this->repository->load($childId) !== null) {
                continue;
            }

            $outbox->add(new LaunchChild(
                $transition->runs,
                $childId,
                $childSubject,
                $saga::class,
                $sagaId,
                $transition->getName(),
                $attempt,
            ));
        }

        return $outbox;
    }

    /**
     * Checks that the persisted saga still fits the definition the running code
     * declares.
     *
     * Sagas outlive deploys. A renamed place leaves a marking Symfony refuses
     * with a raw LogicException, which at the default single attempt means the
     * saga is compensated and deleted — 400 parked orders become 400 refunds
     * over a one-word rename. A renamed transition is worse in the other
     * direction: `can()` merely returns false, so every redelivered job and
     * every external signal becomes a permanent, invisible no-op. Both are
     * reported as {@see SagaDefinitionDriftException} so the queue layer can
     * park the saga instead of rolling it back.
     */
    private function assertMarkingStillFits(WorkflowInterface $workflow, SagaState $state, string $sagaId): void
    {
        $places = array_values($workflow->getDefinition()->getPlaces());

        foreach (array_keys($state->marking) as $place) {
            if (!in_array($place, $places, true)) {
                throw SagaDefinitionDriftException::unknownPlace($sagaId, (string) $place, $places);
            }
        }
    }

    /**
     * A renamed transition is worse than a renamed place, because `can()` merely
     * returns false for a name it does not know — which made every redelivered
     * job and every external signal a permanent, invisible no-op.
     */
    private function assertTransitionStillExists(
        WorkflowInterface $workflow,
        string $sagaId,
        string $transition,
    ): void {
        $names = array_values(array_unique(array_map(
            static fn(Transition $t): string => $t->getName(),
            $workflow->getDefinition()->getTransitions(),
        )));

        if (!in_array($transition, $names, true)) {
            throw SagaDefinitionDriftException::unknownTransition($sagaId, $transition, $names);
        }
    }

    /**
     * Refuses to touch a saga whose rollback did not complete.
     *
     * The row survives a failed compensation because it is the only record of
     * what is still un-undone, but it otherwise looks like a live saga — so a
     * leftover job, or a late signal, would move it forward and re-run steps that
     * have already been undone.
     */
    private function assertRollbackIsNotIncomplete(SagaState $state, string $sagaId): void
    {
        if (in_array(self::ROLLBACK_FAILED, $state->history, true)) {
            throw new SagaException("Saga '$sagaId' has an incomplete rollback and will not be advanced. Its history records "
                . 'a compensation that threw; the row was kept so the rollback can be retried, and '
                . 'moving it forward would re-run steps that have already been undone.');
        }
    }

    /**
     * Rejects a definition with two transitions sharing a name.
     *
     * Symfony fires the whole leave/transition/enter cycle once per matching
     * Transition, so one queued job can execute the action N times — while the
     * runner appends the name to history once, and compensation walks history.
     * N executions would get at most one rollback. Since the entire compensation
     * model keys on names, rejecting is safer than silently under-compensating.
     */
    private function assertTransitionNamesAreUnique(WorkflowInterface $workflow, Saga $saga): void
    {
        $sagaClass = $saga::class;
        $seen = [];

        foreach ($workflow->getDefinition()->getTransitions() as $transition) {
            $name = $transition->getName();
            if (str_starts_with($name, '!saga:')) {
                throw new SagaException("Saga $sagaClass declares a transition named '$name'. The '!saga:' prefix is reserved for the "
                    . 'markers the runner journals into history.');
            }
            if (isset($seen[$name])) {
                throw new SagaException("Saga $sagaClass declares transition name '$name' more than once. Symfony applies every matching "
                    . 'transition, so the action would run once per arc while compensation could only undo it '
                    . 'once. Give each arc a distinct name.');
            }
            $seen[$name] = true;
        }
    }

    /**
     * Refuses a step whose listener wrote to Symfony's apply context.
     *
     * The apply context lives for exactly one apply() and then goes. Symfony
     * hands it to the marking store, whose own implementations drop it and so
     * does {@see SagaMarkingStore}; {@see SagaState} has no field for it. So a
     * listener that stashes a correlation there — a payment intent id to
     * remember which other saga this run belongs to — writes code that looks
     * right, tests green within the step, and has lost the value by the next one.
     * Nothing announced that, which is the entire reason this check exists.
     *
     * Detection is by FOREIGN KEYS, not by the context being non-empty: the
     * runner puts four of its own in on every apply, so a populated apply
     * context is the normal case. Anything else can only have arrived through
     * `$event->setContext()`.
     *
     * Note what a listener calling setContext() actually does: Event::setContext()
     * replaces the array wholesale, so it does not merely add a key — it also
     * throws away the runner's own four, and with them the payload
     * {@see Signal::payload()} reads and the outbox {@see reply()} writes to. All
     * the more reason to be loud.
     *
     * Long-lived run state belongs in the subject, which is the one thing that
     * does survive a step, and which is why there is deliberately no second place
     * for it: two homes for the same fact raise the question of which one is
     * right.
     */
    private function assertNothingWasStashedInTheApplyContext(
        Marking $applied,
        string $sagaId,
        string $transition,
    ): void {
        $foreign = array_values(array_diff(
            array_keys($applied->getContext() ?? []),
            [
                self::SAGA_ID_CONTEXT_KEY,
                self::OUTBOX_CONTEXT_KEY,
                self::CALLER_CONTEXT_KEY,
                self::CHILD_CONTEXT_KEY,
                self::SIGNAL_CONTEXT_KEY,
            ],
        ));

        if ($foreign === []) {
            return;
        }

        throw new SagaException("Transition '$transition' of saga '$sagaId' wrote ".implode(', ', array_map(
            static fn(string $key): string => "'$key'",
            $foreign,
        )).' to the apply context. That context lasts for one apply() and is then dropped — nothing '
            . 'persists it, so the value would be gone by the next step. Put anything that has to outlive '
            . 'the step on the subject instead, which is what SagaState stores.');
    }

    /**
     * Resolves the workflow the saga is registered under, by BOTH subject class
     * and saga FQCN.
     *
     * Matching on the subject alone left three invariants unchecked, all of
     * which failed silently. Symfony's `Workflow::$name` is optional and
     * defaults to 'unnamed', while {@see Saga} tells users to listen on
     * `workflow.<FQCN>.*` — so a workflow registered without the name fired no
     * actions, consulted no guards, and drove the saga to a terminal place. And
     * two sagas sharing a subject DTO broke both with a Symfony exception from
     * outside this library's hierarchy, which the queue layer then treats as a
     * business failure. Passing the name makes Registry check it, so both cases
     * become a loud {@see SagaException} naming the saga.
     */
    private function workflowFor(Saga $saga, object $subject, string $sagaId): WorkflowInterface
    {
        try {
            return $this->workflows->get($subject, $saga::class);
        } catch (WorkflowInvalidArgumentException $e) {
            $sagaClass = $saga::class;
            $subjectClass = $subject::class;

            throw new SagaException("No workflow is registered for saga '$sagaId' ($sagaClass) over subject $subjectClass. The Symfony Workflow must be "
                . 'constructed with the saga FQCN as its $name — that argument is optional in Symfony, and '
                . "omitting it makes every 'workflow.<FQCN>.*' listener silently dead. Registry said: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Checks whether any transition leaves at least one place in the given
     * marking. Used to distinguish a guard-blocked "waiting" state (some
     * transitions exist but none currently enabled) from a structurally
     * terminal place (no outgoing transitions at all).
     */
    private function hasOutgoingTransitions(WorkflowInterface $workflow, Marking $marking): bool
    {
        return array_any($workflow->getDefinition()->getTransitions(), static fn(Transition $transition): bool =>
            array_any($transition->getFroms(), $marking->has(...)));
    }

    /**
     * Symfony's Marking is a multiset — a place can hold more than one token,
     * which is how converging branches and weighted arcs work. Flattening the
     * counts to 1 silently dropped a unit of work every time two branches met
     * in one place.
     *
     * @return array<string, int<1, max>> place name => token count
     */
    private static function markingToArray(Marking $marking): array
    {
        return $marking->getPlaces();
    }
}
