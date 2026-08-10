<?php

declare(strict_types=1);

namespace Techork\Saga;

use Symfony\Component\Workflow\Exception\InvalidArgumentException as WorkflowInvalidArgumentException;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Techork\Saga\Event\CompensateEvent;
use Throwable;

use function array_fill_keys;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function in_array;

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
 * {@see \Symfony\Component\Workflow\Registry}) populated at boot time by
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

    public function __construct(
        private SagaStateRepository      $repository,
        private SagaQueue                $queue,
        private EventDispatcherInterface $dispatcher,
        private Registry                 $workflows,
        private SagaMarkingStore         $markingStore,
        private SagaLock                 $lock,
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
        /** @var array{SagaState, list<string>} $result */
        $result = $this->lock->withLock(
            $sagaId,
            fn(): array => $this->startExclusively($saga, $sagaId, $subject),
        );

        [$state, $dispatch] = $result;
        $this->dispatch($saga, $sagaId, $dispatch);

        return $state;
    }

    /** @return array{SagaState, list<string>} the new state, and the transitions to enqueue */
    private function startExclusively(Saga $saga, string $sagaId, object $subject): array
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

        $enabled = $this->enabled($workflow, $subject);

        // Same three-way classification run() uses. An initial marking with
        // nothing enabled is only an error when nothing *could* ever fire;
        // otherwise the saga legitimately starts parked behind a guard —
        // "created, awaiting approval" is a canonical saga shape.
        if ($enabled === [] && !$this->hasOutgoingTransitions($workflow, $marking)) {
            throw new SagaException(\sprintf(
                "Saga '%s' (%s) cannot start: its initial marking has no outgoing transitions at all, "
                . 'so nothing can ever fire. This is a definition bug.',
                $sagaId,
                $saga::class,
            ));
        }

        $state = new SagaState($sagaId, $this->markingToArray($marking), $subject, version: 1);
        $this->repository->save($state);

        return [$state, $enabled];
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
        /** @var list<string> $dispatch */
        $dispatch = $this->lock->withLock(
            $sagaId,
            fn(): array => $this->runExclusively($saga, $sagaId, $transition),
        );

        $this->dispatch($saga, $sagaId, $dispatch);
    }

    /** @return list<string> transitions to enqueue once the lock is released */
    private function runExclusively(Saga $saga, string $sagaId, string $transition): array
    {
        $state = $this->repository->load($sagaId);
        if ($state === null) {
            // Race: saga was already completed/canceled by a concurrent
            // step. Nothing to do — silently skip rather than throw, since
            // signal-driven `run()` from external callers (webhooks, etc.)
            // may legitimately race with the forward path.
            return [];
        }

        if ($state->marking === []) {
            // Symfony would treat this as "subject not in the workflow yet" and
            // re-seed the initial places, silently restarting the saga and
            // re-running its first action. The library never persists an empty
            // marking, so this is always corruption.
            throw new SagaException(\sprintf(
                "Saga '%s' has an empty marking, which the runner never writes — the row is corrupt.",
                $sagaId,
            ));
        }

        if (in_array(self::ROLLBACK_FAILED, $state->history, true)) {
            throw new SagaException(\sprintf(
                "Saga '%s' has an incomplete rollback and will not be advanced. Its history records "
                . 'a compensation that threw; the row was kept so the rollback can be retried, and '
                . 'moving it forward would re-run steps that have already been undone.',
                $sagaId,
            ));
        }

        $subject = $state->subject;
        $workflow = $this->workflowFor($saga, $subject, $sagaId);

        $this->assertDefinitionStillFits($workflow, $state, $sagaId, $transition);

        // Marking's constructor takes place => token count, so the counts
        // survive the round trip instead of collapsing to one token each.
        $marking = new Marking($state->marking);
        $this->markingStore->setMarking($subject, $marking);

        // Duplicate guard. Reached under the saga lock, so the marking was
        // read exclusively: a job redelivered after this transition was
        // already consumed genuinely finds it no longer fireable, rather than
        // racing a concurrent worker into the same apply().
        if (!$workflow->can($subject, $transition)) {
            return [];
        }

        $workflow->apply($subject, $transition);

        $newMarking = $this->markingStore->getMarking($subject);
        $history = [...$state->history, $transition];
        $enabled = $this->enabled($workflow, $subject);

        // Terminal: no enabled transitions and no outgoing ones from the
        // new marking. The saga has reached a place that structurally
        // accepts no further moves — clean up and exit.
        if (empty($enabled) && !$this->hasOutgoingTransitions($workflow, $newMarking)) {
            $this->repository->delete($sagaId);

            return [];
        }

        // The saga is alive — either advancing or waiting on a guard-blocked
        // external state. Persist the new marking so it can be resumed.
        $this->repository->save(new SagaState(
            $state->id,
            $this->markingToArray($newMarking),
            $subject,
            $history,
            $state->version + 1,
        ));

        // Queue everything fireable. Siblings of a fork that are already in
        // flight get queued a second time, and that is deliberate: correctness
        // comes from the saga lock plus the can() check above, which together
        // reduce a duplicate to a job that takes the lock, finds its transition
        // consumed and returns. Tracking what had been dispatched would save
        // those jobs — an n-way fork costs O(n^2) pushes rather than O(n) — but
        // it would need persisted state to do it, and it would buy efficiency
        // rather than correctness.
        //
        // An empty result is the wait state: alive, nothing fireable, so an
        // external caller will signal run() once a guard passes.
        return $enabled;
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
     * Safe to call on a healthy saga: a step still genuinely queued simply
     * arrives twice, and the saga lock plus the can() check reduce the duplicate
     * to an immediate return.
     *
     * @return list<string> the transitions re-queued
     */
    public function resume(Saga $saga, string $sagaId): array
    {
        /** @var list<string> $dispatch */
        $dispatch = $this->lock->withLock($sagaId, function () use ($saga, $sagaId): array {
            $state = $this->repository->load($sagaId);
            if ($state === null || in_array(self::ROLLBACK_FAILED, $state->history, true)) {
                return [];
            }

            $subject = $state->subject;
            $this->markingStore->setMarking($subject, new Marking($state->marking));

            return $this->enabled($this->workflowFor($saga, $subject, $sagaId), $subject);
        });

        $this->dispatch($saga, $sagaId, $dispatch);

        return $dispatch;
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
    private function assertDefinitionStillFits(
        WorkflowInterface $workflow,
        SagaState $state,
        string $sagaId,
        string $transition,
    ): void {
        $definition = $workflow->getDefinition();

        $places = array_values($definition->getPlaces());
        foreach (array_keys($state->marking) as $place) {
            if (!in_array($place, $places, true)) {
                throw SagaDefinitionDriftException::unknownPlace($sagaId, (string) $place, $places);
            }
        }

        $names = array_values(array_unique(array_map(
            static fn(Transition $t): string => $t->getName(),
            $definition->getTransitions(),
        )));
        if (!in_array($transition, $names, true)) {
            throw SagaDefinitionDriftException::unknownTransition($sagaId, $transition, $names);
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
        $seen = [];
        foreach ($workflow->getDefinition()->getTransitions() as $transition) {
            $name = $transition->getName();
            if (\str_starts_with($name, '!saga:')) {
                throw new SagaException(\sprintf(
                    "Saga %s declares a transition named '%s'. The '!saga:' prefix is reserved for the "
                    . 'markers the runner journals into history.',
                    $saga::class,
                    $name,
                ));
            }
            if (isset($seen[$name])) {
                throw new SagaException(\sprintf(
                    "Saga %s declares transition name '%s' more than once. Symfony applies every matching "
                    . 'transition, so the action would run once per arc while compensation could only undo it '
                    . 'once. Give each arc a distinct name.',
                    $saga::class,
                    $name,
                ));
            }
            $seen[$name] = true;
        }
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
            throw new SagaException(\sprintf(
                "No workflow is registered for saga '%s' (%s) over subject %s. The Symfony Workflow must be "
                . 'constructed with the saga FQCN as its $name — that argument is optional in Symfony, and '
                . "omitting it makes every 'workflow.<FQCN>.*' listener silently dead. Registry said: %s",
                $sagaId,
                $saga::class,
                $subject::class,
                $e->getMessage(),
            ), 0, $e);
        }
    }

    /**
     * Transition names currently fireable, deduplicated.
     *
     * array_values() must stay OUTERMOST: array_unique() preserves keys, so
     * wrapping it the other way round leaves gaps and the result is no longer a
     * list, which the return type promises.
     *
     * @return list<string>
     */
    private function enabled(WorkflowInterface $workflow, object $subject): array
    {
        return array_values(array_unique(array_map(
            static fn(Transition $transition): string => $transition->getName(),
            $workflow->getEnabledTransitions($subject),
        )));
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
