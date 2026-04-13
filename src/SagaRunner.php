<?php

declare(strict_types=1);

namespace Techork\Saga;

use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Techork\Saga\Event\CompensateEvent;
use Throwable;

use function array_unique;
use function array_values;
use function count;

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
 * Compensation is NOT automatic — when a transition throws, the exception
 * bubbles up to the caller (e.g. {@see \Techork\Saga\Laravel\SagaStepJob}).
 * The caller decides when to compensate by invoking
 * {@see compensateAndDelete()} (typically from a Laravel job's `failed()`
 * callback, after all retries are exhausted).
 */
final readonly class SagaRunner
{
    public function __construct(
        private SagaStateRepository      $repository,
        private SagaQueue                $queue,
        private EventDispatcherInterface $dispatcher,
        private Registry                 $workflows,
        private SagaMarkingStore         $markingStore,
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
        $initial = $saga->definition()->getInitialPlaces();
        if ($initial === []) {
            throw new SagaException('Workflow definition has no initial places.');
        }

        $marking = new Marking();
        foreach ($initial as $place) {
            $marking->mark($place);
        }
        $this->markingStore->setMarking($subject, $marking);

        $workflow = $this->workflows->get($subject);
        $enabled = $this->enabled($workflow, $subject);
        empty($enabled) && throw new SagaException('No transitions are enabled at the initial marking.');

        $state = new SagaState($sagaId, $this->markingToArray($marking), $subject, version: 1);
        $this->repository->save($state);

        foreach ($enabled as $name) {
            $this->queue->push($saga::class, $sagaId, $name);
        }

        return $state;
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
     *     several transitions can become enabled simultaneously over disjoint
     *     `from`-sets — that's a Petri-net parallel fork. Designers who need
     *     a choice should model it via guards on a single path; the runner
     *     does not arbitrate shared-`from` races (the loser silent-no-ops on
     *     `workflow->can()` after the winner consumes the token).
     *   - No transitions enabled but at least one structurally outgoing
     *     transition exists from the new marking → the saga is waiting on
     *     external state (a guard that will pass once the world changes).
     *     State is preserved.
     *   - No transitions enabled and no outgoing transitions → marking is
     *     structurally terminal. State is deleted (saga complete).
     *
     * On failure the exception is NOT caught — it bubbles up so the queue
     * layer can apply its retry policy. Call {@see compensateAndDelete()}
     * when all retries are exhausted.
     */
    public function run(Saga $saga, string $sagaId, string $transition): void
    {
        $state = $this->repository->load($sagaId);
        if ($state === null) {
            // Race: saga was already completed/canceled by a concurrent
            // step. Nothing to do — silently skip rather than throw, since
            // signal-driven `run()` from external callers (webhooks, etc.)
            // may legitimately race with the forward path.
            return;
        }

        $subject = $state->subject;

        $marking = new Marking();
        foreach (array_keys($state->marking) as $place) {
            $marking->mark($place);
        }
        $this->markingStore->setMarking($subject, $marking);

        $workflow = $this->workflows->get($subject);

        // Race / duplicate guard.
        if (!$workflow->can($subject, $transition)) {
            return;
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

            return;
        }

        // Otherwise the saga is alive — either advancing or waiting on a
        // guard-blocked external state. Persist the new marking so it can
        // be resumed.
        $this->repository->save(new SagaState(
            $state->id,
            $this->markingToArray($newMarking),
            $subject,
            $history,
            $state->version + 1,
        ));

        if ($enabled === []) {
            // Wait state — saga is alive but no transition is currently
            // fireable. Nothing to queue; an external caller will signal
            // `run()` with a specific transition once a guard passes.
            return;
        }

        foreach ($enabled as $name) {
            $this->queue->push($saga::class, $sagaId, $name);
        }
    }

    /**
     * Runs compensation for every previously-applied transition in reverse
     * order, then deletes the saga state.
     *
     * Call this after all retries for a failed transition are exhausted
     * (e.g. from {@see \Techork\Saga\Laravel\SagaStepJob::failed()}).
     *
     * @return Throwable[] errors thrown by individual compensation listeners,
     *                     in the order they were raised
     */
    public function compensateAndDelete(Saga $saga, string $sagaId): array
    {
        $state = $this->repository->load($sagaId);
        if ($state === null) {
            return [];
        }

        $subject = $state->subject;
        $errors = [];
        $name = $saga::class;

        for ($i = count($state->history) - 1; $i >= 0; $i--) {
            $transition = $state->history[$i];
            $event = new CompensateEvent($name, $sagaId, $transition, $subject);

            try {
                $this->dispatcher->dispatch($event, "saga.$name.compensate.$transition");
            } catch (Throwable $e) {
                $errors[] = $e;
            }
        }

        $this->repository->delete($sagaId);

        return $errors;
    }

    /** @return list<string> */
    private function enabled(WorkflowInterface $workflow, object $subject): array
    {
        return array_unique(array_values(array_map(static function(Transition $transition): string {
            return $transition->getName();
        }, $workflow->getEnabledTransitions($subject))));
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
            array_any($transition->getFroms(), static fn(string $from): bool => $marking->has($from)));
    }

    /**
     * @param  Marking  $marking
     * @return array<string, int>
     */
    private static function markingToArray(Marking $marking): array
    {
        return array_fill_keys(array_keys($marking->getPlaces()), 1);
    }
}
