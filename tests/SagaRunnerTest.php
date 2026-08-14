<?php

declare(strict_types=1);

namespace Techork\Saga\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Event\EnteredEvent;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow;
use Techork\Saga\Event\CompensateEvent;
use Techork\Saga\InMemorySagaQueue;
use Techork\Saga\InMemorySagaStateRepository;
use Techork\Saga\InProcessSagaLock;
use Techork\Saga\Saga;
use Techork\Saga\SagaException;
use Techork\Saga\SagaLock;
use Techork\Saga\SagaMarkingStore;
use Techork\Saga\SagaQueue;
use Techork\Saga\SagaRunner;
use Techork\Saga\Tests\Fixtures\AlphaSaga;
use Techork\Saga\Tests\Fixtures\BetaSaga;

use function array_slice;
use function end;
use function sort;
use function sprintf;

final class SagaRunnerTest extends TestCase
{
    private InMemorySagaStateRepository $repository;

    private InMemorySagaQueue $queue;

    private EventDispatcher $dispatcher;

    private Registry $registry;

    private SagaMarkingStore $markingStore;

    private SagaRunner $runner;

    protected function setUp(): void
    {
        $this->repository = new InMemorySagaStateRepository;
        $this->queue = new InMemorySagaQueue;
        $this->dispatcher = new EventDispatcher;
        $this->registry = new Registry;
        $this->markingStore = new SagaMarkingStore;
        $this->runner = new SagaRunner(
            $this->repository,
            $this->queue,
            $this->dispatcher,
            $this->registry,
            $this->markingStore,
            new InProcessSagaLock,
        );
    }

    // ───────────────── Linear & forward auto-cascade ─────────────────

    public function testLinearWorkflowRunsToCompletion(): void
    {
        $log = [];
        $saga = $this->register(new Definition(
            ['a', 'b', 'c'],
            [new Transition('t1', 'a', 'b'), new Transition('t2', 'b', 'c')],
            ['a'],
        ));

        $this->onTransition($saga, 't1', function (TestSubject $s) use (&$log): void {
            $log[] = 't1';
            $s->counter++;
        });
        $this->onTransition($saga, 't2', function (TestSubject $s) use (&$log): void {
            $log[] = 't2';
            $s->counter++;
        });

        $this->runner->start($saga, 'l-1', new TestSubject);
        $this->drain($saga);

        self::assertSame(['t1', 't2'], $log);
        self::assertNull($this->repository->load('l-1'), 'Terminal state should be deleted');
    }

    public function testForkJoinRunsBothBranchesThenJoins(): void
    {
        $log = [];
        $saga = $this->register($this->forkJoinDefinition());

        foreach (['fork', 'work_a', 'work_b', 'join'] as $t) {
            $this->onTransition($saga, $t, function () use (&$log, $t): void {
                $log[] = $t;
            });
        }

        $this->runner->start($saga, 'fj-1', new TestSubject);
        $this->drain($saga);

        self::assertSame('join', end($log));
        $branches = array_slice($log, 0, -1);
        sort($branches);
        self::assertSame(['fork', 'work_a', 'work_b'], $branches);
        self::assertNull($this->repository->load('fj-1'));
    }

    // ───────────────── Wiring invariants must fail loudly ─────────────────

    public function testAWorkflowRegisteredWithoutItsSagaNameIsRejected(): void
    {
        // Symfony's Workflow $name argument is optional and defaults to
        // 'unnamed', while Saga.php tells users to listen on
        // workflow.<FQCN>.transition.<t>. Omitting it used to fire zero
        // actions, skip every guard, drive the marking to a terminal place and
        // delete the row — indistinguishable from a fast, clean success.
        $definition = new Definition(
            ['a', 'b'],
            [new Transition('t1', 'a', 'b')],
            ['a'],
        );
        $saga = new class($definition) implements Saga
        {
            public function __construct(private Definition $def) {}

            public function definition(): Definition
            {
                return $this->def;
            }

        };

        $this->registry->addWorkflow(
            new Workflow($definition, $this->markingStore, $this->dispatcher),  // no $name
            new InstanceOfSupportStrategy(TestSubject::class),
        );

        $fired = false;
        $this->onTransition($saga, 't1', function () use (&$fired): void {
            $fired = true;
        });

        try {
            $this->runner->start($saga, 'unnamed-1', new TestSubject);
            self::fail('a mis-wired workflow must not look like a working saga');
        } catch (SagaException $e) {
            self::assertStringContainsString('unnamed-1', $e->getMessage());
        }

        self::assertFalse($fired);
        self::assertNull($this->repository->load('unnamed-1'), 'nothing may be persisted');
    }

    public function testTwoSagasOverOneSubjectClassAreDisambiguatedByName(): void
    {
        // Registry::get($subject) alone throws "Too many workflows match this
        // subject" — an InvalidArgumentException outside the library's own
        // hierarchy, which in Laravel lands in failed() and compensates a saga
        // over a pure wiring mistake. Passing the saga FQCN resolves it.
        $alpha = new AlphaSaga;
        $beta = new BetaSaga;

        foreach ([$alpha, $beta] as $saga) {
            $this->registry->addWorkflow(
                new Workflow($saga->definition(), $this->markingStore, $this->dispatcher, $saga::class),
                new InstanceOfSupportStrategy(TestSubject::class),
            );
        }

        $log = [];
        $this->onTransition($alpha, 'alpha', function () use (&$log): void {
            $log[] = 'alpha';
        });
        $this->onTransition($beta, 'beta', function () use (&$log): void {
            $log[] = 'beta';
        });

        $this->runner->start($beta, 'two-1', new TestSubject);
        $this->drain($beta);

        self::assertSame(['beta'], $log, 'the saga argument must select the workflow');
        self::assertNull($this->repository->load('two-1'));
    }

    public function testStartTakesItsInitialPlacesFromTheRegisteredWorkflow(): void
    {
        // start() read initial places from $saga->definition() while apply()
        // used the registry's workflow — two sources of truth for the same
        // thing. Resolving both from the registry removes the divergence.
        $registered = new Definition(
            ['ready', 'done'],
            [new Transition('go', 'ready', 'done')],
            ['ready'],
        );
        $saga = $this->register($registered);

        $state = $this->runner->start($saga, 'src-1', new TestSubject);

        self::assertSame(['ready' => 1], $state->marking);
    }

    // ───────────────── The apply context ─────────────────

    public function testTheApplyContextCarriesFromOnePhaseOfAStepToTheNext(): void
    {
        // Symfony passes the context along the phases of one apply — transition,
        // enter, entered, completed, announce — and that is the channel for what
        // a step needs WHILE it runs. The runner must not get in the way of it.
        //
        // The runner used to refuse any foreign key here, on the theory that
        // writing to a per-apply channel means expecting it to persist. It does
        // mean that sometimes, and that trap is documented on SagaMarkingStore,
        // Signal::payload() and SagaState. But it is indistinguishable from this,
        // which is legitimate, so the refusal killed a step that had just worked.
        $seen = null;
        $saga = $this->register(new Definition(
            ['a', 'b'],
            [new Transition('t1', 'a', 'b')],
            ['a'],
        ));
        $this->onTransitionEvent($saga, 't1', static function (TransitionEvent $e): void {
            $e->setContext([...$e->getContext(), 'computed' => 'xyz']);
        });
        $this->dispatcher->addListener(
            sprintf('workflow.%s.entered.b', $saga::class),
            static function (EnteredEvent $e) use (&$seen): void {
                $seen = $e->getContext()['computed'] ?? null;
            },
        );

        $this->runner->start($saga, 'ctx-1', new TestSubject);
        $msg = $this->queue->pop();
        $this->runner->run($saga, $msg['id'], $msg['transition']);

        self::assertSame('xyz', $seen, 'a later phase of the same step must see it');
        self::assertNull($this->repository->load('ctx-1'), 'and the step must complete');
    }

    public function testWhatAListenerPutsInTheApplyContextIsGoneByTheNextStep(): void
    {
        // The trap itself, pinned rather than refused: the channel lasts one
        // apply. Nothing persists it — SagaState has marking, subject, history
        // and version — so the next step starts with the runner's own keys only.
        $saw = [];
        $saga = $this->register(new Definition(
            ['a', 'b', 'c'],
            [new Transition('t1', 'a', 'b'), new Transition('t2', 'b', 'c')],
            ['a'],
        ));
        $this->onTransitionEvent($saga, 't1', static function (TransitionEvent $e): void {
            $e->setContext([...$e->getContext(), 'remembered' => 'nope']);
        });
        $this->onTransitionEvent($saga, 't2', static function (TransitionEvent $e) use (&$saw): void {
            $saw[] = $e->getContext()['remembered'] ?? 'gone';
        });

        $this->runner->start($saga, 'ctx-2', new TestSubject);
        $this->drain($saga);

        self::assertSame(['gone'], $saw, 'anything meant to outlive the step belongs on the subject');
    }

    // ───────────────── Mutual exclusion ─────────────────

    public function testTheTransitionActionRunsInsideTheSagaLockNotAroundIt(): void
    {
        // If the lock only covered the state read/write, a second worker could
        // slip in while the first is inside its action — which is exactly the
        // interleaving that used to charge a card and then compensate the
        // other branch. Assert the ordering explicitly.
        $trace = [];
        $lock = new class($trace) implements SagaLock {
            /** @param list<string> $trace */
            public function __construct(private array &$trace) {}

            public function withLock(string $sagaId, callable $work): mixed
            {
                $this->trace[] = "acquire:$sagaId";
                try {
                    return $work();
                } finally {
                    $this->trace[] = "release:$sagaId";
                }
            }
        };

        $runner = new SagaRunner(
            $this->repository,
            $this->queue,
            $this->dispatcher,
            $this->registry,
            $this->markingStore,
            $lock,
        );

        $saga = $this->register(new Definition(
            ['a', 'b'],
            [new Transition('t1', 'a', 'b')],
            ['a'],
        ));
        $this->onTransition($saga, 't1', function () use (&$trace): void {
            $trace[] = 'action:t1';
        });

        $runner->start($saga, 'lock-1', new TestSubject);
        $msg = $this->queue->pop();
        $runner->run($saga, $msg['id'], $msg['transition']);

        self::assertSame([
            'acquire:lock-1', 'release:lock-1',    // start()
            'acquire:lock-1', 'action:t1', 'release:lock-1',
        ], $trace);
    }

    public function testASecondWorkerCannotEnterASagaWhileAStepIsRunning(): void
    {
        $saga = $this->register($this->forkJoinDefinition());

        $secondWorker = null;
        $this->onTransition($saga, 'work_a', function () use ($saga, &$secondWorker): void {
            // Stand-in for a concurrent worker picking up the sibling branch.
            // InProcessSagaLock refuses it because the saga is already held.
            try {
                $this->runner->run($saga, 'lk-1', 'work_b');
                $secondWorker = 'ENTERED';
            } catch (SagaException $e) {
                $secondWorker = $e->getMessage();
            }
        });

        $this->runner->start($saga, 'lk-1', new TestSubject);
        $this->runOne($saga, 'fork');
        $this->runOne($saga, 'work_a');

        self::assertNotSame('ENTERED', $secondWorker);
        self::assertStringContainsString('Re-entrant lock acquisition', (string) $secondWorker);
    }

    public function testCompensationTakesTheSameLockAsSteps(): void
    {
        $acquired = [];
        $lock = new class($acquired) implements SagaLock {
            /** @param list<string> $acquired */
            public function __construct(private array &$acquired) {}

            public function withLock(string $sagaId, callable $work): mixed
            {
                $this->acquired[] = $sagaId;

                return $work();
            }
        };

        $runner = new SagaRunner(
            $this->repository,
            $this->queue,
            $this->dispatcher,
            $this->registry,
            $this->markingStore,
            $lock,
        );

        $saga = $this->register(new Definition(
            ['a', 'b', 'c'],
            [new Transition('t1', 'a', 'b'), new Transition('t2', 'b', 'c')],
            ['a'],
        ));

        $runner->start($saga, 'cmp-1', new TestSubject);
        $runner->compensateAndDelete($saga, 'cmp-1');

        // Two compensating workers must not interleave and dispatch the same
        // rollback twice, so compensation is inside the lock too.
        self::assertSame(['cmp-1', 'cmp-1'], $acquired);
    }

    public function testAnInlineDispatcherWorks_pushHappensOutsideTheLock(): void
    {
        // Laravel's `sync` connection executes the job during push(). If the
        // runner pushed while still holding the saga lock, that inline step
        // would re-enter withLock() for the same saga and blow up with a plain
        // SagaException — which SagaStepJob does not treat as a race, so a
        // healthy saga would be compensated and deleted.
        $saga = null;
        $ran = [];

        $queue = new class($ran) implements SagaQueue {
            public ?SagaRunner $runner = null;

            public ?Saga $saga = null;

            /** @param list<string> $ran */
            public function __construct(private array &$ran) {}

            public function push(string $sagaClass, string $sagaId, string $transition, int $delaySeconds = 0): void
            {
                $this->ran[] = $transition;
                $this->runner->run($this->saga, $sagaId, $transition);
            }
        };

        $runner = new SagaRunner(
            $this->repository,
            $queue,
            $this->dispatcher,
            $this->registry,
            $this->markingStore,
            new InProcessSagaLock,
        );
        $queue->runner = $runner;

        $saga = $this->register(new Definition(
            ['a', 'b', 'c'],
            [new Transition('t1', 'a', 'b'), new Transition('t2', 'b', 'c')],
            ['a'],
        ));
        $queue->saga = $saga;

        $runner->start($saga, 'sync-1', new TestSubject);

        self::assertSame(['t1', 't2'], $ran, 'the whole saga must run through an inline dispatcher');
        self::assertNull($this->repository->load('sync-1'));
    }

    public function testABranchUnblockedByExternalStateIsQueuedAfterAWaitState(): void
    {
        // Both branches start guard-blocked, so the saga parks with nothing
        // queued. Once the world changes and an external caller signals ONE
        // branch, the other must be queued too — it was enabled but never
        // dispatched, so "already enabled" is not the same as "already queued".
        $saga = $this->register(new Definition(
            ['start', 'a', 'b', 'a_done', 'b_done', 'done'],
            [
                new Transition('fork', 'start', ['a', 'b']),
                new Transition('ta', 'a', 'a_done'),
                new Transition('tb', 'b', 'b_done'),
                new Transition('join', ['a_done', 'b_done'], 'done'),
            ],
            ['start'],
        ));

        $worldOpen = false;
        $this->onGuard($saga, 'ta', function () use (&$worldOpen): bool {
            return $worldOpen;
        });
        $this->onGuard($saga, 'tb', function () use (&$worldOpen): bool {
            return $worldOpen;
        });

        $done = [];
        foreach (['fork', 'ta', 'tb', 'join'] as $t) {
            $this->onTransition($saga, $t, function () use (&$done, $t): void {
                $done[] = $t;
            });
        }

        $this->runner->start($saga, 'wait-3', new TestSubject);
        $this->drain($saga);

        self::assertSame(['fork'], $done);
        self::assertSame(['a' => 1, 'b' => 1], $this->repository->load('wait-3')?->marking);
        self::assertTrue($this->queue->isEmpty(), 'wait state queues nothing');

        $worldOpen = true;
        $this->runner->run($saga, 'wait-3', 'ta');
        $this->drain($saga);

        self::assertSame(['fork', 'ta', 'tb', 'join'], $done);
        self::assertNull($this->repository->load('wait-3'), 'saga must complete, not strand branch b');
    }

    public function testASagaWithAnIncompleteRollbackRefusesToMoveForward(): void
    {
        // The row survives a failed compensation because it is the only record of
        // what is still un-undone — but the leftover job for the step that threw
        // would otherwise pass can() and run the action again. That is how a
        // refunded order gets charged twice and then ships.
        $charges = 0;
        $saga = $this->register(new Definition(
            ['a', 'b', 'c'],
            [new Transition('charge', 'a', 'b'), new Transition('ship', 'b', 'c')],
            ['a'],
        ));
        $this->onTransition($saga, 'charge', function () use (&$charges): void {
            $charges++;
        });
        $this->onCompensation($saga, 'charge', function (): void {
            throw new RuntimeException('refund endpoint 503');
        });

        $this->runner->start($saga, 'stuck-1', new TestSubject);
        $this->queue->pop();

        self::assertCount(1, $this->runner->compensateAndDelete($saga, 'stuck-1', 'charge'));

        try {
            $this->runner->run($saga, 'stuck-1', 'charge');
            self::fail('a saga with an incomplete rollback must not advance');
        } catch (SagaException $e) {
            self::assertStringContainsString('incomplete rollback', $e->getMessage());
        }

        self::assertSame(0, $charges, 'the action must not run after a failed rollback');
        self::assertSame([], $this->runner->requeue($saga, 'stuck-1'), 'requeue must not revive it either');
    }

    public function testAReservedTransitionNameIsRejected(): void
    {
        $saga = $this->register(new Definition(
            ['a', 'b'],
            [new Transition('!saga:rollback-failed', 'a', 'b')],
            ['a'],
        ));

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('reserved');
        $this->runner->start($saga, 'res-1', new TestSubject);
    }

    // ───────────────── Fan-out: one job per step, no duplicates ─────────────────

    public function testAForkCompletesCorrectlyEvenThoughBranchesRequeueEachOther(): void
    {
        // Every completing branch re-queues its still-pending siblings, so an
        // n-way fork costs O(n^2) pushes. That is a deliberate trade: suppressing
        // it would need the set of dispatched steps persisted on the row, and it
        // buys job count, not correctness. Correctness comes from the saga lock
        // plus the can() check — a duplicate takes the lock, finds its transition
        // already consumed, and returns.
        $ran = [];
        $saga = $this->register(new Definition(
            ['start', 'a', 'b', 'c', 'a_done', 'b_done', 'c_done', 'done'],
            [
                new Transition('fork', 'start', ['a', 'b', 'c']),
                new Transition('wa', 'a', 'a_done'),
                new Transition('wb', 'b', 'b_done'),
                new Transition('wc', 'c', 'c_done'),
                new Transition('join', ['a_done', 'b_done', 'c_done'], 'done'),
            ],
            ['start'],
        ));

        foreach (['fork', 'wa', 'wb', 'wc', 'join'] as $t) {
            $this->onTransition($saga, $t, function () use (&$ran, $t): void {
                $ran[] = $t;
            });
        }

        $this->runner->start($saga, 'fan-1', new TestSubject);
        $dequeued = $this->drainLog($saga);

        sort($ran);
        self::assertSame(['fork', 'join', 'wa', 'wb', 'wc'], $ran, 'no action may run twice');
        self::assertNull($this->repository->load('fan-1'));

        // The wasted jobs are real and bounded by the fork width.
        self::assertGreaterThan(5, count($dequeued), 'duplicates are expected without dispatch tracking');
        self::assertLessThanOrEqual(14, count($dequeued));
    }

    public function testADuplicateJobForAnAlreadyAppliedTransitionIsANoOp(): void
    {
        $charges = 0;
        $saga = $this->register(new Definition(
            ['a', 'b', 'c'],
            [new Transition('charge', 'a', 'b'), new Transition('ship', 'b', 'c')],
            ['a'],
        ));
        $this->onTransition($saga, 'charge', function () use (&$charges): void {
            $charges++;
        });

        $this->runner->start($saga, 'dup-1', new TestSubject);
        $this->queue->pop();

        $this->runner->run($saga, 'dup-1', 'charge');
        $this->runner->run($saga, 'dup-1', 'charge');
        $this->runner->run($saga, 'dup-1', 'charge');

        self::assertSame(1, $charges, 'the lock serialises them and can() rejects the replay');
    }

    public function testRequeuePushesWhatIsFireableAfterALostHandOff(): void
    {
        // run() persists and then pushes, two steps with nothing tying them
        // together. If the push is lost the saga is alive with nothing in flight.
        // Recovery is explicit: doing it automatically on every replayed job
        // turned each duplicate into another push and made a two-branch fork grow
        // 2^L instead of linearly.
        $saga = $this->register(new Definition(
            ['a', 'b', 'c'],
            [new Transition('t1', 'a', 'b'), new Transition('t2', 'b', 'c')],
            ['a'],
        ));

        $this->runner->start($saga, 'heal-1', new TestSubject);
        $this->queue->pop();
        $this->runner->run($saga, 'heal-1', 't1');

        // Pretend the queue dropped t2.
        $this->queue->pop();
        self::assertTrue($this->queue->isEmpty());

        // A redelivered job for the already-applied step must stay silent.
        $this->runner->run($saga, 'heal-1', 't1');
        self::assertTrue($this->queue->isEmpty(), 'a replay must not manufacture pushes');

        self::assertSame(['t2'], $this->runner->requeue($saga, 'heal-1'));
        self::assertSame(['t2'], $this->queue->transitions());
    }

    public function testSelfLoopKeepsRequeueingItself(): void
    {
        // A transition consuming a token it just produced (t: a -> a) is the
        // idiomatic polling loop. Suppressing duplicates purely by "was it
        // already enabled" would silently kill it after one pass.
        $saga = $this->register(new Definition(
            ['a', 'done'],
            [new Transition('tick', 'a', 'a'), new Transition('finish', 'a', 'done')],
            ['a'],
        ));

        $this->onGuard($saga, 'tick', fn (TestSubject $s) => $s->counter < 3);
        $this->onGuard($saga, 'finish', fn (TestSubject $s) => $s->counter >= 3);
        $this->onTransition($saga, 'tick', function (TestSubject $s): void {
            $s->counter++;
        });

        $this->runner->start($saga, 'loop-1', new TestSubject);
        $dequeued = $this->drainLog($saga);

        self::assertSame(['tick', 'tick', 'tick', 'finish'], $dequeued);
        self::assertNull($this->repository->load('loop-1'));
    }

    public function testTransitionUnblockedByAnotherBranchsGuardFlipIsQueued(): void
    {
        // 'tb' lives in a parallel branch whose token 'ta' never touches, so a
        // "did this apply put a token in tb's from-place" rule alone would
        // never queue it. It becomes fireable only because ta's action mutated
        // the subject — the runner must notice the enablement itself changed.
        $saga = $this->register(new Definition(
            ['start', 'a', 'b', 'a_done', 'b_done', 'done'],
            [
                new Transition('fork', 'start', ['a', 'b']),
                new Transition('ta', 'a', 'a_done'),
                new Transition('tb', 'b', 'b_done'),
                new Transition('join', ['a_done', 'b_done'], 'done'),
            ],
            ['start'],
        ));

        $this->onGuard($saga, 'tb', fn (TestSubject $s) => $s->outcome === 'unlocked');
        $this->onTransition($saga, 'ta', function (TestSubject $s): void {
            $s->outcome = 'unlocked';
        });

        $this->runner->start($saga, 'flip-1', new TestSubject);
        $dequeued = $this->drainLog($saga);

        self::assertSame(['fork', 'ta', 'tb', 'join'], $dequeued);
        self::assertNull($this->repository->load('flip-1'), 'Saga must reach its terminal place, not stall');
    }

    // ───────────────── Guards selecting branch ─────────────────

    public function testGuardsPickChoiceByContext(): void
    {
        $log = [];
        $saga = $this->register(new Definition(
            ['start', 'left', 'right', 'end'],
            [
                new Transition('go_left', 'start', 'left'),
                new Transition('go_right', 'start', 'right'),
                new Transition('finish_left', 'left', 'end'),
                new Transition('finish_right', 'right', 'end'),
            ],
            ['start'],
        ));

        $this->onGuard($saga, 'go_left', fn (TestSubject $s) => $s->path === 'left');
        $this->onGuard($saga, 'go_right', fn (TestSubject $s) => $s->path === 'right');
        foreach (['go_left', 'go_right', 'finish_left', 'finish_right'] as $t) {
            $this->onTransition($saga, $t, function () use (&$log, $t): void {
                $log[] = $t;
            });
        }

        $subject = new TestSubject;
        $subject->path = 'right';
        $this->runner->start($saga, 'g-1', $subject);
        $this->drain($saga);

        self::assertSame(['go_right', 'finish_right'], $log);
    }

    public function testGuardSeesPostTransitionSubjectMutation(): void
    {
        $reached = [];
        $saga = $this->register(new Definition(
            ['start', 'decided', 'ok', 'bad'],
            [
                new Transition('decide', 'start', 'decided'),
                new Transition('success', 'decided', 'ok'),
                new Transition('failure', 'decided', 'bad'),
            ],
            ['start'],
        ));

        $this->onTransition($saga, 'decide', function (TestSubject $s): void {
            $s->outcome = 'failure';
        });
        $this->onGuard($saga, 'success', fn (TestSubject $s) => $s->outcome === 'success');
        $this->onGuard($saga, 'failure', fn (TestSubject $s) => $s->outcome === 'failure');
        $this->onTransition($saga, 'success', function () use (&$reached): void {
            $reached[] = 'success';
        });
        $this->onTransition($saga, 'failure', function () use (&$reached): void {
            $reached[] = 'failure';
        });

        $this->runner->start($saga, 'p-1', new TestSubject);
        $this->drain($saga);

        self::assertSame(['failure'], $reached);
    }

    // ───────────────── Wait state (guards block all) ─────────────────

    public function testGuardBlockedAtAllOutgoingPreservesStateAsWait(): void
    {
        $saga = $this->register(new Definition(
            ['start', 'pending_signal', 'done'],
            [
                new Transition('begin', 'start', 'pending_signal'),
                new Transition('resume', 'pending_signal', 'done'),
            ],
            ['start'],
        ));

        // 'resume' guard always blocks — until something external decides.
        $this->onGuard($saga, 'resume', fn () => false);

        $this->runner->start($saga, 'wait-1', new TestSubject);
        $this->drain($saga);

        // Saga sits in 'pending_signal' — no enabled transitions, but
        // 'resume' is structurally outgoing → state must be preserved.
        $state = $this->repository->load('wait-1');
        self::assertNotNull($state, 'Wait state must be preserved');
        self::assertSame(['pending_signal' => 1], $state->marking);
        self::assertTrue($this->queue->isEmpty(), 'Nothing queued — saga is waiting for external trigger');
    }

    public function testExternalRunResumesWaitingSaga(): void
    {
        $log = [];
        $saga = $this->register(new Definition(
            ['start', 'pending_signal', 'done'],
            [
                new Transition('begin', 'start', 'pending_signal'),
                new Transition('resume', 'pending_signal', 'done'),
            ],
            ['start'],
        ));

        $unblocked = false;
        $this->onGuard($saga, 'resume', function () use (&$unblocked): bool {
            return $unblocked;
        });
        $this->onTransition($saga, 'resume', function () use (&$log): void {
            $log[] = 'resume';
        });

        $this->runner->start($saga, 'wait-2', new TestSubject);
        $this->drain($saga);  // saga lands in pending_signal

        self::assertNotNull($this->repository->load('wait-2'));

        // External world changes — caller signals saga.
        $unblocked = true;
        $this->runner->run($saga, 'wait-2', 'resume');

        self::assertSame(['resume'], $log);
        self::assertNull($this->repository->load('wait-2'), 'Saga should complete after resume');
    }

    public function testRunOnMissingStateIsSilent(): void
    {
        $saga = $this->register(new Definition(
            ['a', 'b'],
            [new Transition('t1', 'a', 'b')],
            ['a'],
        ));

        // No throw — race-safe for signal-driven external callers.
        $this->runner->run($saga, 'never-existed', 't1');

        self::assertNull($this->repository->load('never-existed'));
    }

    // ───────────────── Failure & compensation ─────────────────

    public function testFailureBubblesWithoutAutoCompensation(): void
    {
        $saga = $this->register($this->forkJoinDefinition());

        $this->onTransition($saga, 'work_b', function (): void {
            throw new RuntimeException('b broke');
        });

        $this->runner->start($saga, 'fj-x', new TestSubject);
        $this->runOne($saga, 'fork');
        $this->runOne($saga, 'work_a');

        try {
            $this->runOne($saga, 'work_b');
            self::fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertSame('b broke', $e->getMessage());
        }

        self::assertNotNull($this->repository->load('fj-x'));
    }

    public function testCompensateAndDeleteRunsInReverseAndCleansUp(): void
    {
        $compensations = [];
        $saga = $this->register($this->forkJoinDefinition());

        foreach (['fork', 'work_a'] as $t) {
            $this->onCompensation($saga, $t, function () use (&$compensations, $t): void {
                $compensations[] = $t;
            });
        }
        $this->onTransition($saga, 'work_b', function (): void {
            throw new RuntimeException('b broke');
        });

        $this->runner->start($saga, 'fj-y', new TestSubject);
        $this->runOne($saga, 'fork');
        $this->runOne($saga, 'work_a');

        try {
            $this->runOne($saga, 'work_b');
        } catch (RuntimeException) {
            // expected
        }

        $errors = $this->runner->compensateAndDelete($saga, 'fj-y');

        self::assertSame([], $errors);
        self::assertSame(['work_a', 'fork'], $compensations);
        self::assertNull($this->repository->load('fj-y'));
    }

    public function testCompensationEventCarriesSubject(): void
    {
        $captured = null;
        $saga = $this->register(new Definition(
            ['a', 'b', 'c'],
            [new Transition('t1', 'a', 'b'), new Transition('t2', 'b', 'c')],
            ['a'],
        ));

        $this->dispatcher->addListener(
            sprintf('saga.%s.compensate.t1', $saga::class),
            static function (CompensateEvent $event) use (&$captured): void {
                $captured = $event;
            },
        );
        $this->onTransition($saga, 't2', function (): void {
            throw new RuntimeException('t2 failed');
        });

        $subject = new TestSubject;
        $subject->path = 'observed';
        $this->runner->start($saga, 'cs-1', $subject);
        $this->runOne($saga, 't1');

        try {
            $this->runOne($saga, 't2');
        } catch (RuntimeException) {
            // expected
        }
        $this->runner->compensateAndDelete($saga, 'cs-1');

        self::assertNotNull($captured);
        self::assertInstanceOf(TestSubject::class, $captured->subject);
        /** @var TestSubject $passed */
        $passed = $captured->subject;
        self::assertSame('observed', $passed->path);
    }

    public function testCompensationErrorsAreCollectedNotReThrown(): void
    {
        $saga = $this->register(new Definition(
            ['a', 'b', 'c'],
            [new Transition('t1', 'a', 'b'), new Transition('t2', 'b', 'c')],
            ['a'],
        ));

        $this->onCompensation($saga, 't1', function (): void {
            throw new RuntimeException('t1 rollback broken');
        });
        $this->onTransition($saga, 't2', function (): void {
            throw new RuntimeException('t2 failed');
        });

        $this->runner->start($saga, 'c-1', new TestSubject);
        $this->runOne($saga, 't1');

        try {
            $this->runOne($saga, 't2');
        } catch (RuntimeException) {
            // expected
        }

        $errors = $this->runner->compensateAndDelete($saga, 'c-1');

        self::assertCount(1, $errors);
        self::assertSame('t1 rollback broken', $errors[0]->getMessage());

        // The row must survive a failed rollback: it holds the subject and the
        // history needed to retry it. Deleting would destroy the only record of
        // what is still un-undone.
        $state = $this->repository->load('c-1');
        self::assertNotNull($state, 'a failed rollback must not delete the saga');
        self::assertSame(['t1', SagaRunner::ROLLBACK_FAILED], $state->history, 'and must be marked');
    }

    public function testASuccessfulRollbackDeletesTheSaga(): void
    {
        $saga = $this->register(new Definition(
            ['a', 'b', 'c'],
            [new Transition('t1', 'a', 'b'), new Transition('t2', 'b', 'c')],
            ['a'],
        ));

        $this->onCompensation($saga, 't1', function (): void {});

        $this->runner->start($saga, 'c-2', new TestSubject);
        $this->runOne($saga, 't1');

        self::assertSame([], $this->runner->compensateAndDelete($saga, 'c-2'));
        self::assertNull($this->repository->load('c-2'));
    }

    // ───────────────── The failing transition is compensated too ─────────────────

    public function testTheFailingTransitionIsCompensatedFirstEvenThoughItIsNotInHistory(): void
    {
        // History is written only after a step persists, and a step whose action
        // throws never persists — so the one transition guaranteed to have run,
        // and to have run only partway, used to be the only one never rolled back.
        $compensated = [];
        $saga = $this->register(new Definition(
            ['a', 'b', 'c'],
            [new Transition('reserve', 'a', 'b'), new Transition('charge', 'b', 'c')],
            ['a'],
        ));

        foreach (['reserve', 'charge'] as $t) {
            $this->onCompensation($saga, $t, function () use (&$compensated, $t): void {
                $compensated[] = $t;
            });
        }
        $this->onTransition($saga, 'charge', function (): void {
            throw new RuntimeException('captured the card, then the invoice write timed out');
        });

        $this->runner->start($saga, 'f-1', new TestSubject);
        $this->runOne($saga, 'reserve');

        $cause = null;
        try {
            $this->runOne($saga, 'charge');
        } catch (RuntimeException $e) {
            $cause = $e;
        }

        self::assertSame(['reserve'], $this->repository->load('f-1')?->history, 'charge is absent from history');

        $errors = $this->runner->compensateAndDelete($saga, 'f-1', 'charge', $cause);

        self::assertSame([], $errors);
        self::assertSame(['charge', 'reserve'], $compensated, 'failing step first, then reverse history');
        self::assertNull($this->repository->load('f-1'));
    }

    public function testCompensationEventCarriesTheCauseAndMarksTheFailingStep(): void
    {
        $seen = [];
        $saga = $this->register(new Definition(
            ['a', 'b', 'c'],
            [new Transition('reserve', 'a', 'b'), new Transition('charge', 'b', 'c')],
            ['a'],
        ));

        foreach (['reserve', 'charge'] as $t) {
            $this->onCompensation($saga, $t, function (CompensateEvent $e) use (&$seen): void {
                $seen[$e->transition] = [
                    'failed' => $e->failed,
                    'cause' => $e->cause?->getMessage(),
                ];
            });
        }

        $this->runner->start($saga, 'f-2', new TestSubject);
        $this->runOne($saga, 'reserve');

        $cause = new RuntimeException('gateway said no');
        $this->runner->compensateAndDelete($saga, 'f-2', 'charge', $cause);

        self::assertSame(['failed' => true, 'cause' => 'gateway said no'], $seen['charge']);
        self::assertSame(['failed' => false, 'cause' => 'gateway said no'], $seen['reserve']);
    }

    public function testCompensatingWithoutNamingAFailedTransitionStillWalksHistory(): void
    {
        // Callers that genuinely have no failing step — an operator cancelling a
        // parked saga — must keep the old behaviour.
        $compensated = [];
        $saga = $this->register(new Definition(
            ['a', 'b', 'c'],
            [new Transition('t1', 'a', 'b'), new Transition('t2', 'b', 'c')],
            ['a'],
        ));

        foreach (['t1', 't2'] as $t) {
            $this->onCompensation($saga, $t, function () use (&$compensated, $t): void {
                $compensated[] = $t;
            });
        }

        $this->runner->start($saga, 'f-3', new TestSubject);
        $this->runOne($saga, 't1');
        $this->runner->compensateAndDelete($saga, 'f-3');

        self::assertSame(['t1'], $compensated);
    }

    public function testCompensateAndDeleteReturnsEmptyWhenStateNotFound(): void
    {
        $saga = $this->register(new Definition(
            ['a', 'b'],
            [new Transition('t1', 'a', 'b')],
            ['a'],
        ));

        self::assertSame([], $this->runner->compensateAndDelete($saga, 'nonexistent'));
    }

    // ───────────────── Helpers ─────────────────

    private function drain(Saga $saga): void
    {
        while (($msg = $this->queue->pop()) !== null) {
            $this->runner->run($saga, $msg['id'], $msg['transition']);
        }
    }

    /**
     * Drains the queue and returns every transition actually dequeued, in
     * order. Because drain() runs until the queue is empty, this is also the
     * exact list of everything that was ever pushed — which is what the
     * fan-out assertions need.
     *
     * @return list<string>
     */
    private function drainLog(Saga $saga, int $guard = 50): array
    {
        $seen = [];
        while (($msg = $this->queue->pop()) !== null) {
            self::assertLessThan($guard, count($seen), 'runaway fan-out: '.implode(',', $seen));
            $seen[] = $msg['transition'];
            $this->runner->run($saga, $msg['id'], $msg['transition']);
        }

        return $seen;
    }

    private function runOne(Saga $saga, string $expected): void
    {
        $msg = $this->queue->pop();
        self::assertNotNull($msg, "expected a queued message for {$expected}");
        self::assertSame($expected, $msg['transition']);
        $this->runner->run($saga, $msg['id'], $msg['transition']);
    }

    /** Like onTransition(), but the listener receives the event itself. */
    private function onTransitionEvent(Saga $saga, string $transition, callable $listener): void
    {
        $this->dispatcher->addListener(
            sprintf('workflow.%s.transition.%s', $saga::class, $transition),
            $listener,
        );
    }

    private function onTransition(Saga $saga, string $transition, Closure $fn): void
    {
        $this->dispatcher->addListener(
            sprintf('workflow.%s.transition.%s', $saga::class, $transition),
            static fn (TransitionEvent $e) => $fn($e->getSubject()),
        );
    }

    private function onGuard(Saga $saga, string $transition, Closure $predicate): void
    {
        $this->dispatcher->addListener(
            sprintf('workflow.%s.guard.%s', $saga::class, $transition),
            static function (GuardEvent $e) use ($predicate): void {
                if (! $predicate($e->getSubject())) {
                    $e->setBlocked(true);
                }
            },
        );
    }

    private function onCompensation(Saga $saga, string $transition, Closure $fn): void
    {
        $this->dispatcher->addListener(
            sprintf('saga.%s.compensate.%s', $saga::class, $transition),
            static fn (CompensateEvent $e) => $fn($e),
        );
    }

    private function forkJoinDefinition(): Definition
    {
        return new Definition(
            ['start', 'a', 'b', 'a_done', 'b_done', 'done'],
            [
                new Transition('fork', 'start', ['a', 'b']),
                new Transition('work_a', 'a', 'a_done'),
                new Transition('work_b', 'b', 'b_done'),
                new Transition('join', ['a_done', 'b_done'], 'done'),
            ],
            ['start'],
        );
    }


    private function register(Definition $definition): Saga
    {
        $saga = new class($definition) implements Saga
        {
            public function __construct(private Definition $def) {}

            public function definition(): Definition
            {
                return $this->def;
            }

        };

        $workflow = new Workflow($definition, $this->markingStore, $this->dispatcher, $saga::class);
        $this->registry->addWorkflow($workflow, new InstanceOfSupportStrategy(TestSubject::class));

        return $saga;
    }
}
