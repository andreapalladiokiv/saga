<?php

declare(strict_types=1);

namespace Techork\Saga\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Arc;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow;
use Techork\Saga\Call;
use Techork\Saga\InMemorySagaQueue;
use Techork\Saga\InMemorySagaStateRepository;
use Techork\Saga\InProcessSagaLock;
use Techork\Saga\Saga;
use Techork\Saga\SagaException;
use Techork\Saga\SagaLocator;
use Techork\Saga\SagaMarkingStore;
use Techork\Saga\SagaQueue;
use Techork\Saga\SagaRunner;
use Techork\Saga\Signal;
use Techork\Saga\Tests\Call\ChallengeFailed;
use Techork\Saga\Tests\Call\ChallengePassed;
use Techork\Saga\Tests\Call\CheckoutSaga;
use Techork\Saga\Tests\Call\CheckoutSubject;
use Techork\Saga\Tests\Call\PaymentAuthorized;
use Techork\Saga\Tests\Call\PaymentDeclined;
use Techork\Saga\Tests\Call\PaymentIntentSaga;
use Techork\Saga\Tests\Call\PaymentIntentSubject;

use function array_shift;
use function sprintf;

/**
 * A Call is a Signal that runs another saga while it waits.
 *
 * What these pin is mostly about WHERE things happen: the launch and the answer
 * are performed by the runner outside the saga lock, which is the difference
 * between this and a bridge written by hand — the latter deadlocks under an
 * inline queue driver, and these tests run every case under one.
 */
final class CallTest extends TestCase
{
    private InMemorySagaStateRepository $repository;

    private EventDispatcher $dispatcher;

    private Registry $registry;

    private SagaMarkingStore $markingStore;

    private SagaRunner $runner;

    private CheckoutSaga $checkout;

    private PaymentIntentSaga $intent;

    /** @var list<string> */
    private array $log = [];

    /**
     * class => saga, shared by reference with the inline queue and the locator so
     * a test can register a parent of its own and have both find it.
     *
     * @var array<class-string<Saga>, Saga>
     */
    private array $sagas = [];

    /** Both exits out of `declined` are guarded; the tests open the one they mean. */
    private bool $allowRetry = false;

    private bool $allowAbandon = false;

    protected function setUp(): void
    {
        $this->repository = new InMemorySagaStateRepository;
        $this->dispatcher = new EventDispatcher;
        $this->registry = new Registry;
        $this->markingStore = new SagaMarkingStore;
        $this->checkout = new CheckoutSaga;
        $this->intent = new PaymentIntentSaga;
    }

    /**
     * Every test runs on an INLINE driver — push() executes the step at once.
     * That is the setting a hand-written bridge cannot survive, so it is the one
     * worth defaulting to.
     */
    private function boot(): void
    {
        $queue = new class($this->log, $this->sagas) implements SagaQueue
        {
            public ?SagaRunner $runner = null;

            /**
             * @param  list<string>  $log
             * @param  array<class-string<Saga>, Saga>  $sagas
             */
            public function __construct(private array &$log, private array &$sagas) {}

            public function push(string $sagaClass, string $sagaId, string $transition, int $delaySeconds = 0): void
            {
                $this->log[] = "step:$transition#$sagaId";
                $this->runner?->run($this->sagas[$sagaClass], $sagaId, $transition);
            }
        };

        $this->runner = new SagaRunner(
            $this->repository,
            $queue,
            $this->dispatcher,
            $this->registry,
            $this->markingStore,
            new InProcessSagaLock,
            $this->locator(),
        );

        $queue->runner = $this->runner;
        $this->sagas = [CheckoutSaga::class => $this->checkout, PaymentIntentSaga::class => $this->intent];

        $this->registry->addWorkflow(
            new Workflow($this->checkout->definition(), $this->markingStore, $this->dispatcher, CheckoutSaga::class),
            new InstanceOfSupportStrategy(CheckoutSubject::class),
        );
        $this->registry->addWorkflow(
            new Workflow($this->intent->definition(), $this->markingStore, $this->dispatcher, PaymentIntentSaga::class),
            new InstanceOfSupportStrategy(PaymentIntentSubject::class),
        );

        $this->on(CheckoutSaga::class, 'pay', function (TransitionEvent $e): void {
            $answer = Signal::payload($e, PaymentAuthorized::class);
            $e->getSubject()->authCode = $answer->authCode;
            $this->log[] = 'checkout:authorized:'.$answer->authCode;
        });
        $this->on(CheckoutSaga::class, 'payment_declined', function (TransitionEvent $e): void {
            $this->log[] = 'checkout:declined:'.Signal::payload($e, PaymentDeclined::class)->reason;
        });
        $this->on(CheckoutSaga::class, 'settle', function (): void {
            $this->log[] = 'checkout:settled';
        });

        $this->dispatcher->addListener(sprintf('workflow.%s.guard.retry', CheckoutSaga::class),
            function (GuardEvent $e): void {
                if (! $this->allowRetry) {
                    $e->setBlocked(true);
                }
            });
        $this->dispatcher->addListener(sprintf('workflow.%s.guard.abandon', CheckoutSaga::class),
            function (GuardEvent $e): void {
                if (! $this->allowAbandon) {
                    $e->setBlocked(true);
                }
            });

        // the child answers; it names no target, because it cannot
        $this->on(PaymentIntentSaga::class, 'challenge_passed', function (TransitionEvent $e): void {
            $code = Signal::payload($e, ChallengePassed::class)->authCode;
            $e->getSubject()->authCode = $code;
            $this->log[] = 'intent:passed';
            SagaRunner::reply($e, new PaymentAuthorized($code));
        });
        $this->on(PaymentIntentSaga::class, 'challenge_failed', function (TransitionEvent $e): void {
            $reason = Signal::payload($e, ChallengeFailed::class)->reason;
            $this->log[] = 'intent:failed';
            SagaRunner::reply($e, new PaymentDeclined($reason));
        });
    }

    private function locator(): SagaLocator
    {
        return new class($this->sagas) implements SagaLocator
        {
            /** @param array<class-string<Saga>, Saga> $sagas */
            public function __construct(private array &$sagas) {}

            public function get(string $sagaClass): Saga
            {
                return $this->sagas[$sagaClass] ?? throw new SagaException("unknown $sagaClass");
            }
        };
    }

    private function registerParent(Saga $saga): void
    {
        $this->sagas[$saga::class] = $saga;
        $this->registry->addWorkflow(
            new Workflow($saga->definition(), $this->markingStore, $this->dispatcher, $saga::class),
            new InstanceOfSupportStrategy(CheckoutSubject::class),
        );
    }

    private function on(string $sagaClass, string $transition, callable $listener): void
    {
        $this->dispatcher->addListener(sprintf('workflow.%s.transition.%s', $sagaClass, $transition), $listener);
    }

    /** The id the runner derives; no test may invent its own. */
    private function childOf(string $parentId, string $transition, int $step): string
    {
        return "$parentId/$transition/$step";
    }

    // ───────────────── the launch ─────────────────

    public function testEnteringThePlaceACallLeavesStartsTheSagaItNames(): void
    {
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        $child = $this->childOf('chk-1', 'pay', 1);
        $state = $this->repository->load($child);

        self::assertNotNull($state, 'the child must exist once the parent parked on the Call');
        self::assertInstanceOf(PaymentIntentSubject::class, $state->subject);
        self::assertSame('ord-1', $state->subject->reference, 'the Call builds the subject from the parent’s');
        self::assertSame(['awaiting_challenge' => 1], $state->marking);
    }

    public function testTheChildIsBuiltFromWhatTheStepJustWroteNotFromTheStoredRow(): void
    {
        // The step that carries the saga into the parking place is usually the
        // one that obtained what the child needs — a signal folding in a card,
        // say. Building the child's subject from the row as it was BEFORE that
        // step loses exactly that.
        $this->boot();
        $this->on(CheckoutSaga::class, 'place', function (TransitionEvent $e): void {
            $e->getSubject()->amount = '99.00';
        });

        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        $child = $this->repository->load($this->childOf('chk-1', 'pay', 1));
        self::assertNotNull($child);
        self::assertInstanceOf(PaymentIntentSubject::class, $child->subject);
        self::assertSame('99.00', $child->subject->amount);
    }

    public function testTheParentParksOnTheCallAndNothingIsQueuedForIt(): void
    {
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        self::assertSame(['awaiting_payment' => 1], $this->repository->load('chk-1')?->marking);
        self::assertSame(['step:place#chk-1', 'step:create#chk-1/pay/1'], $this->log,
            'a Call is a Signal, so the runner queues it for nobody');
    }

    public function testTheChildKnowsNothingAboutItsCaller(): void
    {
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        $subject = $this->repository->load($this->childOf('chk-1', 'pay', 1))?->subject;

        self::assertInstanceOf(PaymentIntentSubject::class, $subject);
        foreach ((array) $subject as $value) {
            self::assertNotSame('chk-1', $value, 'no field of the child may carry the caller’s id');
        }
    }

    public function testTwoCallsMayLeaveOnePlaceWhenTheirGuardsMakeThemExclusive(): void
    {
        // The shape is legitimate and common: one wait, two kinds of child, the
        // guards choosing. Rejecting it over the definition alone rejected the
        // shape instead of the fault.
        $saga = new class implements Saga
        {
            public function definition(): Definition
            {
                return new Definition(['a', 'card', 'plan'], [
                    new Call('open_payment', 'a', 'card',
                        runs: PaymentIntentSaga::class, awaits: PaymentAuthorized::class,
                        subject: static fn (CheckoutSubject $s): object
                            => new PaymentIntentSubject('card-'.$s->orderId, $s->amount)),
                    new Call('open_subscription_payment', 'a', 'plan',
                        runs: PaymentIntentSaga::class, awaits: PaymentDeclined::class,
                        subject: static fn (CheckoutSubject $s): object
                            => new PaymentIntentSubject('plan-'.$s->orderId, $s->amount)),
                ], ['a']);
            }
        };
        $this->boot();
        $this->registerParent($saga);

        // no plan: the subscription Call is blocked
        $this->dispatcher->addListener(sprintf('workflow.%s.guard.open_subscription_payment', $saga::class),
            static fn (GuardEvent $e) => $e->setBlocked(true));

        $this->runner->start($saga, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        self::assertNotNull($this->repository->load('chk-1/open_payment/1'), 'the guard-selected Call ran');
        self::assertNull($this->repository->load('chk-1/open_subscription_payment/1'), 'the blocked one did not');
        self::assertSame(['a' => 1], $this->repository->load('chk-1')?->marking,
            'and the caller is still parked: a Call moves only when the answer comes');
    }

    public function testASagaBornParkedOnACallReportsItsFirstAttemptAsOne(): void
    {
        // Arriving in an initial place is an entry that no transition records, so
        // without counting it the first attempt here would be 0 while every other
        // first attempt is 1 — a difference an id rule taking $attempt would see.
        $saga = new class implements Saga
        {
            public function definition(): Definition
            {
                return new Definition(['waiting', 'done'], [
                    new Call('ask', 'waiting', 'done',
                        runs: PaymentIntentSaga::class, awaits: PaymentAuthorized::class,
                        subject: static fn (CheckoutSubject $s): object
                            => new PaymentIntentSubject($s->orderId, $s->amount)),
                ], ['waiting']);
            }
        };
        $this->boot();
        $this->registerParent($saga);

        $this->runner->start($saga, 'chk-3', new CheckoutSubject('ord-3', '10.00'));

        self::assertNotNull($this->repository->load('chk-3/ask/1'));
    }

    public function testAForkMayRunOneCallPerBranchAtTheSameTime(): void
    {
        // The refusal is per PLACE, not per step, so a Petri-net fork with a Call
        // on each branch launches both children and waits for both answers. The
        // two Calls must await different types, or the answer cannot be routed.
        $saga = new class implements Saga
        {
            public function definition(): Definition
            {
                return new Definition(['start', 'left', 'right', 'got_left', 'got_right', 'done'], [
                    new Transition('split', 'start', ['left', 'right']),
                    new Call('ask_left', 'left', 'got_left',
                        runs: PaymentIntentSaga::class, awaits: PaymentAuthorized::class,
                        subject: static fn (CheckoutSubject $s): object
                            => new PaymentIntentSubject('l-'.$s->orderId, $s->amount)),
                    new Call('ask_right', 'right', 'got_right',
                        runs: PaymentIntentSaga::class, awaits: PaymentDeclined::class,
                        subject: static fn (CheckoutSubject $s): object
                            => new PaymentIntentSubject('r-'.$s->orderId, $s->amount)),
                    new Transition('join', ['got_left', 'got_right'], 'done'),
                ], ['start']);
            }
        };
        $this->boot();
        $this->registerParent($saga);

        $this->runner->start($saga, 'fork-1', new CheckoutSubject('ord-f', '10.00'));

        $left = 'fork-1/ask_left/1';
        $right = 'fork-1/ask_right/1';
        self::assertNotNull($this->repository->load($left), 'both branches launched');
        self::assertNotNull($this->repository->load($right));
        $parent = $this->repository->load('fork-1');
        self::assertNotNull($parent);
        self::assertEqualsCanonicalizing(['left' => 1, 'right' => 1], $parent->marking);

        // each child answers its own caller; the join waits for both
        $this->runner->signal($this->intent, $left, new ChallengePassed('auth-l'));
        $parent = $this->repository->load('fork-1');
        self::assertNotNull($parent);
        self::assertEqualsCanonicalizing(['got_left' => 1, 'right' => 1], $parent->marking,
            'one answer in, the other branch still waiting');

        $this->runner->signal($this->intent, $right, new ChallengeFailed('nope'));

        self::assertNull($this->repository->load('fork-1'), 'the join fired and the saga finished');
    }

    public function testOnePlaceHoldingTwoTokensMayRunTwoCallsOutOfIt(): void
    {
        // The refusal is arithmetic, not per place: firing a Call consumes a
        // token, so a place holding two can see two of its Calls through.
        $saga = new class implements Saga
        {
            public function definition(): Definition
            {
                return new Definition(['start', 'both', 'a_done', 'b_done'], [
                    new Transition('split', 'start', [new Arc('both', 2)]),
                    new Call('ask_a', 'both', 'a_done',
                        runs: PaymentIntentSaga::class, awaits: PaymentAuthorized::class,
                        subject: static fn (CheckoutSubject $s): object
                            => new PaymentIntentSubject('a-'.$s->orderId, $s->amount)),
                    new Call('ask_b', 'both', 'b_done',
                        runs: PaymentIntentSaga::class, awaits: PaymentDeclined::class,
                        subject: static fn (CheckoutSubject $s): object
                            => new PaymentIntentSubject('b-'.$s->orderId, $s->amount)),
                ], ['start']);
            }
        };
        $this->boot();
        $this->registerParent($saga);

        $this->runner->start($saga, 'two-1', new CheckoutSubject('ord-t', '10.00'));

        self::assertSame(['both' => 2], $this->repository->load('two-1')?->marking);
        self::assertNotNull($this->repository->load('two-1/ask_a/1'), 'both Calls launched');
        self::assertNotNull($this->repository->load('two-1/ask_b/1'));
    }

    public function testTwoCallsFireableOutOfOnePlaceAtOnceIsRejected(): void
    {
        // The actual fault: guards that fail to be exclusive. Both children would
        // start, and only one answer can ever be consumed.
        $saga = new class implements Saga
        {
            public function definition(): Definition
            {
                return new Definition(['a', 'b', 'c'], [
                    new Call('one', 'a', 'b',
                        runs: PaymentIntentSaga::class, awaits: PaymentAuthorized::class,
                        subject: static fn (CheckoutSubject $s): object
                            => new PaymentIntentSubject('x', $s->amount)),
                    new Call('two', 'a', 'c',
                        runs: PaymentIntentSaga::class, awaits: PaymentDeclined::class,
                        subject: static fn (CheckoutSubject $s): object
                            => new PaymentIntentSubject('y', $s->amount)),
                ], ['a']);
            }
        };
        $this->boot();
        $this->registerParent($saga);

        try {
            $this->runner->start($saga, 'chk-2', new CheckoutSubject('ord-2', '49.99'));
            self::fail('more live Calls than the place has tokens must be refused');
        } catch (SagaException $e) {
            self::assertStringContainsString("place 'a'", $e->getMessage());
            self::assertStringContainsString('holds 1 token', $e->getMessage());
            self::assertStringContainsString('choice', $e->getMessage(), 'the message must name what the shape is');
            self::assertStringContainsString("'one'", $e->getMessage());
            self::assertStringContainsString("'two'", $e->getMessage());
        }

        self::assertNull($this->repository->load('chk-2'), 'nothing may be persisted');
    }

    // ───────────────── the answer ─────────────────

    public function testTheChildsAnswerFiresTheCallAndCarriesItsPayload(): void
    {
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        $this->runner->signal($this->intent, $this->childOf('chk-1', 'pay', 1), new ChallengePassed('auth-77'));

        self::assertContains('checkout:authorized:auth-77', $this->log);
        self::assertContains('checkout:settled', $this->log);
        self::assertNull($this->repository->load('chk-1'), 'the checkout ran to the end');
        self::assertNull($this->repository->load($this->childOf('chk-1', 'pay', 1)));
    }

    public function testTheAnswerIsDeliveredWithNoLockHeldSoAnInlineDriverDoesNotDeadlock(): void
    {
        // The whole point. A listener that called signal() on the caller from
        // inside the child's step would re-enter the caller's lock; the runner
        // delivers the answer after releasing the child's.
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        $this->runner->signal($this->intent, $this->childOf('chk-1', 'pay', 1), new ChallengePassed('auth-77'));

        self::assertSame([
            'step:place#chk-1',
            'step:create#chk-1/pay/1',
            'intent:passed',
            'checkout:authorized:auth-77',
            'step:settle#chk-1',
            'checkout:settled',
        ], $this->log);
    }

    public function testAnAnswerOfTheWrongTypeTakesTheOtherExitFromTheSamePlace(): void
    {
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        $this->runner->signal($this->intent, $this->childOf('chk-1', 'pay', 1), new ChallengeFailed('no funds'));

        self::assertContains('checkout:declined:no funds', $this->log);
        self::assertSame(['declined' => 1], $this->repository->load('chk-1')?->marking,
            'payment_declined is an ordinary Signal out of the parking place');
    }

    public function testASagaCanAskWhetherAnyoneCalledIt(): void
    {
        // The same saga runs both ways: launched by a Call, and started directly
        // by an endpoint. It has to be able to tell, without matching on the text
        // of an exception.
        $this->boot();

        $seen = [];
        $this->on(PaymentIntentSaga::class, 'create', function (TransitionEvent $e) use (&$seen): void {
            $seen[SagaRunner::sagaId($e)] = SagaRunner::hasCaller($e);
        });

        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));
        $this->runner->start($this->intent, 'pi-direct', new PaymentIntentSubject('ord-9', '5.00'));

        self::assertTrue($seen[$this->childOf('chk-1', 'pay', 1)] ?? null, 'a child has a caller');
        self::assertFalse($seen['pi-direct'] ?? null, 'one started directly has none');
    }

    public function testASagaWithNoCallerCannotReply(): void
    {
        $this->boot();

        // started directly, so there is nobody to answer
        $this->runner->start($this->intent, 'pi-standalone', new PaymentIntentSubject('ord-9', '5.00'));

        $this->expectException(SagaException::class);
        $this->expectExceptionMessageMatches('/has no caller/');

        $this->runner->signal($this->intent, 'pi-standalone', new ChallengePassed('auth-x'));
    }

    // ───────────────── retries and recovery ─────────────────

    public function testASecondAttemptGetsItsOwnChild(): void
    {
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));
        $this->runner->signal($this->intent, $this->childOf('chk-1', 'pay', 1), new ChallengeFailed('no funds'));

        $this->allowRetry = true;
        $this->runner->run($this->checkout, 'chk-1', 'retry');

        // the attempt number counts entries into the parking place, so the second
        // wait is 2 regardless of how many other steps the parent took
        $second = $this->childOf('chk-1', 'pay', 2);
        self::assertNotNull($this->repository->load($second),
            'a parent that loops back into the parking place must not collide with the first child');
        self::assertSame(['awaiting_payment' => 1], $this->repository->load('chk-1')?->marking);
    }

    public function testARedeliveredStepDoesNotStartASecondChild(): void
    {
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        $before = $this->repository->load($this->childOf('chk-1', 'pay', 1));
        $this->runner->run($this->checkout, 'chk-1', 'place');       // already applied
        $after = $this->repository->load($this->childOf('chk-1', 'pay', 1));

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertSame($before->version, $after->version, 'the child must be untouched');
    }

    public function testRequeueRecreatesAChildTheParentIsWaitingForButWhichIsGone(): void
    {
        // The one hole a Call leaves: the parent commits and the process dies
        // before the launch runs. Recoverable only because the id is derived.
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        $child = $this->childOf('chk-1', 'pay', 1);
        $this->repository->delete($child);
        self::assertNull($this->repository->load($child));

        $this->runner->requeue($this->checkout, 'chk-1');

        self::assertNotNull($this->repository->load($child), 'the sweep must recreate the missing child');
    }

    public function testAStepThatThrowsLaunchesNothing(): void
    {
        $this->boot();
        $this->on(CheckoutSaga::class, 'place', function (): void {
            throw new RuntimeException('the order could not be placed');
        });

        try {
            $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));
            self::fail('the failing step must propagate');
        } catch (RuntimeException) {
            // expected
        }

        self::assertNull($this->repository->load($this->childOf('chk-1', 'place', 1)));
        self::assertNull($this->repository->load($this->childOf('chk-1', 'pay', 1)),
            'a step that never persisted must not have started anything');
    }

    // ───────────────── the other driver ─────────────────

    public function testTheSameFlowRunsOnAQueueThatDefersEveryStep(): void
    {
        // The inline driver is the hard case for locks; a real queue is the hard
        // case for ordering, because a step that is merely enqueued has not run.
        // Both must produce the same outcome.
        $pending = [];
        $queue = new class($pending) implements SagaQueue
        {
            /** @param list<array{class-string<Saga>, string, string}> $pending */
            public function __construct(private array &$pending) {}

            public function push(string $sagaClass, string $sagaId, string $transition, int $delaySeconds = 0): void
            {
                $this->pending[] = [$sagaClass, $sagaId, $transition];
            }
        };

        $this->sagas = [CheckoutSaga::class => $this->checkout, PaymentIntentSaga::class => $this->intent];
        $runner = new SagaRunner(
            $this->repository,
            $queue,
            $this->dispatcher,
            $this->registry,
            $this->markingStore,
            new InProcessSagaLock,
            $this->locator(),
        );
        $this->registry->addWorkflow(
            new Workflow($this->checkout->definition(), $this->markingStore, $this->dispatcher, CheckoutSaga::class),
            new InstanceOfSupportStrategy(CheckoutSubject::class),
        );
        $this->registry->addWorkflow(
            new Workflow($this->intent->definition(), $this->markingStore, $this->dispatcher, PaymentIntentSaga::class),
            new InstanceOfSupportStrategy(PaymentIntentSubject::class),
        );
        $this->on(PaymentIntentSaga::class, 'challenge_passed', function (TransitionEvent $e): void {
            SagaRunner::reply($e, new PaymentAuthorized(Signal::payload($e, ChallengePassed::class)->authCode));
        });
        $this->on(CheckoutSaga::class, 'pay', function (TransitionEvent $e): void {
            $e->getSubject()->authCode = Signal::payload($e, PaymentAuthorized::class)->authCode;
        });

        $sagas = [CheckoutSaga::class => $this->checkout, PaymentIntentSaga::class => $this->intent];
        $drain = static function () use (&$pending, $runner, $sagas): void {
            $guard = 0;
            while ($pending !== [] && $guard++ < 20) {
                [$class, $id, $transition] = array_shift($pending);
                $runner->run($sagas[$class], $id, $transition);
            }
        };

        $runner->start($this->checkout, 'chk-q', new CheckoutSubject('ord-q', '49.99'));
        $drain();

        $child = $this->childOf('chk-q', 'pay', 1);
        self::assertNotNull($this->repository->load($child), 'the child is launched on a real queue too');
        self::assertSame(['awaiting_payment' => 1], $this->repository->load('chk-q')?->marking);

        $runner->signal($this->intent, $child, new ChallengePassed('auth-q'));
        $drain();

        self::assertNull($this->repository->load('chk-q'), 'the checkout settled and finished');
        self::assertNull($this->repository->load($child));
    }

    // ───────────────── compensation ─────────────────

    public function testCompensationSkipsTheRunnersOwnHistoryMarkers(): void
    {
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        $compensated = [];
        $this->dispatcher->addListener(
            sprintf('saga.%s.compensate.create', PaymentIntentSaga::class),
            function () use (&$compensated): void {
                $compensated[] = 'create';
            },
        );

        $errors = $this->runner->compensateAndDelete($this->intent, $this->childOf('chk-1', 'pay', 1));

        self::assertSame([], $errors);
        self::assertSame(['create'], $compensated,
            'the caller marker in history is not a transition and must not be compensated');
    }
}
