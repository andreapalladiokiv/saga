<?php

declare(strict_types=1);

namespace Techork\Saga\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow;
use Techork\Saga\Event\CompensateEvent;
use Techork\Saga\InMemorySagaQueue;
use Techork\Saga\InMemorySagaStateRepository;
use Techork\Saga\Saga;
use Techork\Saga\SagaMarkingStore;
use Techork\Saga\SagaRunner;

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
        self::assertNull($this->repository->load('c-1'));
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

    private function drainOne(Saga $saga): void
    {
        $msg = $this->queue->pop();
        self::assertNotNull($msg, 'expected one queued message');
        $this->runner->run($saga, $msg['id'], $msg['transition']);
    }

    private function runOne(Saga $saga, string $expected): void
    {
        $msg = $this->queue->pop();
        self::assertNotNull($msg, "expected a queued message for {$expected}");
        self::assertSame($expected, $msg['transition']);
        $this->runner->run($saga, $msg['id'], $msg['transition']);
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
