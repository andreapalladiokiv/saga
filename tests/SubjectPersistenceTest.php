<?php

declare(strict_types=1);

namespace Techork\Saga\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Definition;
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
use Techork\Saga\SagaDefinitionDriftException;
use Techork\Saga\SagaException;
use Techork\Saga\SagaMarkingStore;
use Techork\Saga\SagaRunner;
use Techork\Saga\SagaState;
use Techork\Saga\PlainSubjectCodec;

use function sprintf;

final class SubjectPersistenceTest extends TestCase
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

    // ───────── the in-memory repository must enforce the shipped contract ─────────

    public function testTheInMemoryRepositoryRoundTripsTheSubjectLikeTheDatabaseOne(): void
    {
        // It used to be a bare array write handing back the identical live
        // object, so every runner test proved semantics the shipped repository
        // does not have. That is why so many defects passed a green suite.
        $subject = new TestSubject;
        $subject->path = 'original';

        $this->repository->save(new SagaState('p-1', ['a' => 1], $subject, [], 1));

        $loaded = $this->repository->load('p-1');
        self::assertNotSame($subject, $loaded?->subject, 'the subject must be a snapshot, not the live object');
        self::assertSame('original', $loaded->subject->path);

        // Mutating the original must not leak into the stored state.
        $subject->path = 'mutated-after-save';
        self::assertSame('original', $this->repository->load('p-1')->subject->path);
    }

    public function testANonSerializableSubjectFailsLoudlyInMemoryToo(): void
    {
        // A subject holding a closure used to pass every in-memory test and then
        // throw a bare Exception on the first production save.
        $subject = new class {
            public Closure $callback;

            public function __construct()
            {
                $this->callback = static fn (): string => 'nope';
            }
        };

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('cannot be serialized');
        $this->repository->save(new SagaState('p-2', ['a' => 1], $subject, [], 1));
    }

    public function testCompensationSeesThePersistedSnapshotNotLaterMutations(): void
    {
        $seen = null;
        $saga = $this->register(new Definition(
            ['a', 'b', 'c'],
            [new Transition('t1', 'a', 'b'), new Transition('t2', 'b', 'c')],
            ['a'],
        ));

        $this->onTransition($saga, 't1', function (TestSubject $s): void {
            $s->path = 'after-t1';
        });
        $this->dispatcher->addListener(
            sprintf('saga.%s.compensate.t1', $saga::class),
            static function (CompensateEvent $e) use (&$seen): void {
                $seen = $e->subject->path;
            },
        );

        $this->runner->start($saga, 'p-3', new TestSubject);
        $this->runOne($saga, 't1');
        $this->runner->compensateAndDelete($saga, 'p-3');

        self::assertSame('after-t1', $seen, 'the last persisted snapshot, not a live instance');
    }

    // ───────────────── object injection and drift ─────────────────

    public function testASubjectHoldingNestedTypedObjectsRoundTrips(): void
    {
        // An allowed_classes allow-list is an exact match on ONE class name, so it
        // turned every nested object into __PHP_Incomplete_Class — and assigning
        // that to a typed property is a raw TypeError from inside unserialize().
        // Provenance, not an allow-list, is what makes the payload safe.
        $codec = new PlainSubjectCodec;
        $subject = new NestedSubject(new Amount(4999), ['a', 'b']);

        $back = $codec->decode($codec->encode($subject), 'n-1');

        self::assertInstanceOf(NestedSubject::class, $back);
        self::assertInstanceOf(Amount::class, $back->total, 'a nested object must not come back as a ghost');
        self::assertSame(4999, $back->total->cents);
        self::assertSame(['a', 'b'], $back->lines);
    }

    public function testASubclassOfTheDeclaredSubjectRoundTrips(): void
    {
        $codec = new PlainSubjectCodec;
        $back = $codec->decode($codec->encode(new PrioritySubject), 'n-2');

        self::assertInstanceOf(PrioritySubject::class, $back);
    }

    public function testDecodingRefusesAnIncompleteClassInsteadOfAcceptingAGhost(): void
    {
        // A moved or renamed subject class yields __PHP_Incomplete_Class, for
        // which is_object() is TRUE — so it used to sail past the guard and get
        // handed to compensation listeners, which then refunded using nulls.
        $codec = new PlainSubjectCodec;
        $cls = 'App\\Sagas\\GoneSubject';
        $payload = sprintf('O:%d:"%s":1:{s:4:"path";s:2:"hi";}', strlen($cls), $cls);

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('x-3');
        $codec->decode($payload, 'x-3');
    }

    public function testAMarkingReferencingAPlaceTheDefinitionNoLongerHasIsReportedAsDrift(): void
    {
        // A one-word rename in a deploy: every in-flight saga parked in that
        // place used to raise a raw LogicException, which at tries()=1 meant
        // failed() compensated and deleted it. 400 orders, 400 refunds.
        $saga = $this->register(new Definition(
            ['start', 'awaiting_signal', 'done'],
            [
                new Transition('begin', 'start', 'awaiting_signal'),
                new Transition('resume', 'awaiting_signal', 'done'),
            ],
            ['start'],
        ));

        $this->repository->save(new SagaState(
            'drift-1',
            ['pending_signal' => 1],       // the pre-rename place name
            new TestSubject,
            ['begin'],
            1,
        ));

        try {
            $this->runner->run($saga, 'drift-1', 'resume');
            self::fail('a marking the definition cannot express must be reported');
        } catch (SagaDefinitionDriftException $e) {
            self::assertStringContainsString('pending_signal', $e->getMessage());
            self::assertStringContainsString('drift-1', $e->getMessage());
        }
    }

    public function testAnUnknownTransitionNameIsReportedAsDriftNotSilentlyIgnored(): void
    {
        // can() simply returns false for a name that is not in the definition,
        // so a renamed transition made every redelivered job and every external
        // signal a permanent, invisible no-op.
        $saga = $this->register(new Definition(
            ['a', 'b'],
            [new Transition('t1', 'a', 'b')],
            ['a'],
        ));

        $this->runner->start($saga, 'drift-2', new TestSubject);

        $this->expectException(SagaDefinitionDriftException::class);
        $this->expectExceptionMessage('t_renamed');
        $this->runner->run($saga, 'drift-2', 't_renamed');
    }

    public function testAGuardBlockedTransitionStillReturnsQuietly(): void
    {
        // The counterpart: a transition that EXISTS but is not currently
        // fireable is a wait state, not drift, and must stay silent.
        $saga = $this->register(new Definition(
            ['a', 'b'],
            [new Transition('t1', 'a', 'b')],
            ['a'],
        ));

        $this->dispatcher->addListener(
            sprintf('workflow.%s.guard.t1', $saga::class),
            static function (\Symfony\Component\Workflow\Event\GuardEvent $e): void {
                $e->setBlocked(true);
            },
        );

        $this->repository->save(new SagaState('quiet-1', ['a' => 1], new TestSubject, [], 1));

        $this->runner->run($saga, 'quiet-1', 't1');

        self::assertNotNull($this->repository->load('quiet-1'), 'still waiting, no error');
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
}

/** Stands in for a deserialization gadget; must never be woken. */
final class ExplodingGadget
{
    public static bool $woken = false;

    public function __wakeup(): void
    {
        self::$woken = true;
        throw new RuntimeException('gadget executed');
    }
}
