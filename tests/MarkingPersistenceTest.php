<?php

declare(strict_types=1);

namespace Techork\Saga\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow;
use Techork\Saga\InMemorySagaQueue;
use Techork\Saga\InMemorySagaStateRepository;
use Techork\Saga\InProcessSagaLock;
use Techork\Saga\Saga;
use Techork\Saga\SagaException;
use Techork\Saga\SagaMarkingStore;
use Techork\Saga\SagaRunner;
use Techork\Saga\SagaState;

use function sprintf;

/**
 * Symfony's Marking is a multiset: mark() increments a per-place counter and
 * getPlaces() returns place => token count. Flattening that to 1 on the way in
 * and out of storage silently drops work.
 */
final class MarkingPersistenceTest extends TestCase
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

    public function testAPlaceHoldingTwoTokensSurvivesPersistence(): void
    {
        // fork puts a token in x and y; both converge on 'pool'. Raw Symfony
        // reaches {"pool": 2}; the persisted form must say so too, or the
        // second unit of work is lost.
        $saga = $this->register(new Definition(
            ['start', 'x', 'y', 'pool', 'done'],
            [
                new Transition('fork', 'start', ['x', 'y']),
                new Transition('mx', 'x', 'pool'),
                new Transition('my', 'y', 'pool'),
                new Transition('drain', 'pool', 'done'),
            ],
            ['start'],
        ));

        $drained = 0;
        $this->onTransition($saga, 'drain', function () use (&$drained): void {
            $drained++;
        });

        $this->runner->start($saga, 'm-1', new TestSubject);
        $this->runOne($saga, 'fork');
        $this->runOne($saga, 'mx');

        // Only 'my' has yet to move its token; the pool already holds one.
        $this->runner->run($saga, 'm-1', 'my');

        $state = $this->repository->load('m-1');
        self::assertNotNull($state);
        self::assertSame(['pool' => 2], $state->marking, 'both tokens must be recorded');

        $this->drain($saga);

        self::assertSame(2, $drained, 'two tokens in the pool means two drains');
        self::assertNull($this->repository->load('m-1'));
    }

    public function testTokenCountsRoundTripThroughTheStateObject(): void
    {
        $saga = $this->register(new Definition(
            ['p', 'q'],
            [new Transition('t', 'p', 'q')],
            ['p'],
        ));

        $this->repository->save(new SagaState('rt-1', ['p' => 3], new TestSubject, [], 1));

        self::assertSame(['p' => 3], $this->repository->load('rt-1')?->marking);
    }

    // ───────────── loaded state is validated, not silently repaired ─────────────

    public function testAnEmptyMarkingIsRejectedInsteadOfRestartingTheSaga(): void
    {
        // Symfony's Workflow::getMarking() treats an empty marking as "subject
        // not in the workflow yet" and re-seeds the initial places. Through
        // run() that silently restarts the saga and re-fires its first action.
        $charges = 0;
        $saga = $this->register(new Definition(
            ['a', 'b', 'c'],
            [new Transition('t1', 'a', 'b'), new Transition('t2', 'b', 'c')],
            ['a'],
        ));
        $this->onTransition($saga, 't1', function () use (&$charges): void {
            $charges++;
        });

        $this->runner->start($saga, 'corrupt-1', new TestSubject);
        $this->runOne($saga, 't1');
        self::assertSame(1, $charges);

        // A botched manual repair, a partial restore, or a repository that
        // returns [] — the library itself can never persist an empty marking.
        $broken = $this->repository->load('corrupt-1');
        $this->repository->save(new SagaState(
            $broken->id,
            [],
            $broken->subject,
            $broken->history,
            $broken->version + 1,
        ));

        try {
            $this->runner->run($saga, 'corrupt-1', 't1');
            self::fail('an empty marking must be reported, not silently restarted');
        } catch (SagaException $e) {
            self::assertStringContainsString('corrupt-1', $e->getMessage());
        }

        self::assertSame(1, $charges, 'the first action must not run twice');
    }

    // ───────────── definitions the compensation model cannot express ─────────────

    public function testADefinitionWithDuplicateTransitionNamesIsRejected(): void
    {
        // Symfony fires the full event cycle once per matching Transition, but
        // the runner records one history entry per NAME — so N executions get
        // at most one rollback. The whole compensation model keys on names.
        $saga = $this->register(new Definition(
            ['start', 'a', 'b', 'x', 'y'],
            [
                new Transition('fork', 'start', ['a', 'b']),
                new Transition('t', 'a', 'x'),
                new Transition('t', 'b', 'y'),
            ],
            ['start'],
        ));

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage("transition name 't'");
        $this->runner->start($saga, 'dup-1', new TestSubject);
    }

    // ───────────── a saga may legitimately start in a wait state ─────────────

    public function testASagaWhoseFirstTransitionIsGuardBlockedStartsAsAWaitState(): void
    {
        // "Created, awaiting approval" is a canonical saga shape. start()
        // applied the opposite rule to run() and threw before persisting, so
        // the later approval signal found no state and was dropped.
        $saga = $this->register(new Definition(
            ['created', 'approved'],
            [new Transition('approve', 'created', 'approved')],
            ['created'],
        ));

        $approved = false;
        $this->onGuard($saga, 'approve', function () use (&$approved): bool {
            return $approved;
        });
        $ran = [];
        $this->onTransition($saga, 'approve', function () use (&$ran): void {
            $ran[] = 'approve';
        });

        $state = $this->runner->start($saga, 'wait-x', new TestSubject);

        self::assertSame(['created' => 1], $state->marking);
        self::assertNotNull($this->repository->load('wait-x'), 'the saga must exist while it waits');
        self::assertTrue($this->queue->isEmpty());

        $approved = true;
        $this->runner->run($saga, 'wait-x', 'approve');

        self::assertSame(['approve'], $ran);
        self::assertNull($this->repository->load('wait-x'));
    }

    public function testAStructurallyTerminalInitialMarkingIsStillAnError(): void
    {
        // Nothing outgoing at all is a definition bug, not a wait state.
        $saga = $this->register(new Definition(
            ['only'],
            [],
            ['only'],
        ));

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('no outgoing transitions');
        $this->runner->start($saga, 'dead-1', new TestSubject);
    }

    // ───────────────── helpers ─────────────────

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

        $this->registry->addWorkflow(
            new Workflow($definition, $this->markingStore, $this->dispatcher, $saga::class),
            new InstanceOfSupportStrategy(TestSubject::class),
        );

        return $saga;
    }

    private function drain(Saga $saga, int $guard = 30): void
    {
        $n = 0;
        while (($msg = $this->queue->pop()) !== null) {
            self::assertLessThan($guard, $n++, 'runaway fan-out');
            $this->runner->run($saga, $msg['id'], $msg['transition']);
        }
    }

    private function runOne(Saga $saga, string $expected): void
    {
        $msg = $this->queue->pop();
        self::assertNotNull($msg, "expected a queued message for {$expected}");
        self::assertSame($expected, $msg['transition']);
        $this->runner->run($saga, $msg['id'], $msg['transition']);
    }

    private function onTransition(Saga $saga, string $transition, \Closure $fn): void
    {
        $this->dispatcher->addListener(
            sprintf('workflow.%s.transition.%s', $saga::class, $transition),
            static fn (TransitionEvent $e) => $fn($e->getSubject()),
        );
    }

    private function onGuard(Saga $saga, string $transition, \Closure $predicate): void
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
}
