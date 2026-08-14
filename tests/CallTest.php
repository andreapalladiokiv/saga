<?php

declare(strict_types=1);

namespace Techork\Saga\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy;
use Symfony\Component\Workflow\Workflow;
use Techork\Saga\InMemorySagaStateRepository;
use Techork\Saga\InProcessSagaLock;
use Techork\Saga\Saga;
use Techork\Saga\SagaException;
use Techork\Saga\SagaMarkingStore;
use Techork\Saga\SagaQueue;
use Techork\Saga\SagaRunner;
use Techork\Saga\Signal;
use Techork\Saga\Tests\Call\ChallengeFailed;
use Techork\Saga\Tests\Call\ChallengePassed;
use Techork\Saga\Tests\Call\CheckoutSaga;
use Techork\Saga\Tests\Call\CheckoutSubject;
use Techork\Saga\Tests\Call\PaymentIntentSaga;
use Techork\Saga\Tests\Call\PaymentIntentSubject;
use Throwable;

use function sprintf;

/**
 * A Call runs another saga and resumes when it ENDS, taking that saga's final
 * subject as the payload.
 *
 * Nothing is answered and nothing is sent: a saga's state is its subject, so its
 * result is its subject, and it reports by finishing. One writer per row follows —
 * the child owns its subject while it lives, the caller only reads it, afterwards.
 *
 * What these mostly pin is WHERE things happen. The launch and the collection are
 * performed by the runner outside the saga lock, which is the difference between
 * this and a bridge written by hand: the latter deadlocks under an inline queue
 * driver, and every test here runs under one.
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

    /** @var array<class-string<Saga>, Saga> shared by reference with the inline queue */
    private array $sagas = [];

    protected function setUp(): void
    {
        $this->repository = new InMemorySagaStateRepository;
        $this->dispatcher = new EventDispatcher;
        $this->registry = new Registry;
        $this->markingStore = new SagaMarkingStore;
        $this->intent = new PaymentIntentSaga;
        $this->checkout = new CheckoutSaga($this->intent);
    }

    /**
     * Every test runs on an INLINE driver — push() executes the step at once. That
     * is the setting a hand-written bridge cannot survive, so it is the one worth
     * defaulting to.
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

                // a test drops a saga from the map to make a push fail on purpose,
                // standing in for a worker that could not resolve it
                if (! isset($this->sagas[$sagaClass])) {
                    throw new RuntimeException("no worker for $sagaClass");
                }

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

        // the caller reads the child's subject and writes its own
        $this->on(CheckoutSaga::class, 'pay', function (TransitionEvent $e): void {
            $result = Signal::payload($e, PaymentIntentSubject::class);
            $e->getSubject()->authCode = $result->authCode;
            $e->getSubject()->declineReason = $result->declined;
            $this->log[] = 'checkout:collected:'.($result->authCode ?? $result->declined);
        });
        $this->on(CheckoutSaga::class, 'settle', function (): void {
            $this->log[] = 'checkout:settled';
        });
        $this->on(CheckoutSaga::class, 'abandon', function (): void {
            $this->log[] = 'checkout:abandoned';
        });

        // one Call, one target: the branch afterwards is a guard on copied data
        $this->guard(CheckoutSaga::class, 'settle', static fn (GuardEvent $e): bool
            => $e->getSubject()->authCode !== null);
        $this->guard(CheckoutSaga::class, 'abandon', static fn (GuardEvent $e): bool
            => $e->getSubject()->authCode === null);

        // the child only ever writes its own subject
        $this->on(PaymentIntentSaga::class, 'challenge_passed', function (TransitionEvent $e): void {
            $e->getSubject()->authCode = Signal::payload($e, ChallengePassed::class)->authCode;
            $this->log[] = 'intent:passed';
        });
        $this->on(PaymentIntentSaga::class, 'challenge_failed', function (TransitionEvent $e): void {
            $e->getSubject()->declined = Signal::payload($e, ChallengeFailed::class)->reason;
            $this->log[] = 'intent:failed';
        });
    }

    private function on(string $sagaClass, string $transition, callable $listener): void
    {
        $this->dispatcher->addListener(sprintf('workflow.%s.transition.%s', $sagaClass, $transition), $listener);
    }

    private function guard(string $sagaClass, string $transition, callable $allow): void
    {
        $this->dispatcher->addListener(
            sprintf('workflow.%s.guard.%s', $sagaClass, $transition),
            static function (GuardEvent $e) use ($allow): void {
                if (! $allow($e)) {
                    $e->setBlocked(true);
                }
            },
        );
    }

    /** The id the runner derives; no test may invent its own. */
    private function childOf(string $parentId, string $transition, int $attempt): string
    {
        return "$parentId/$transition/$attempt";
    }

    // ───────────────── the launch ─────────────────

    public function testEnteringThePlaceACallLeavesStartsTheSagaItNames(): void
    {
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        $state = $this->repository->load($this->childOf('chk-1', 'pay', 1));

        self::assertNotNull($state, 'the child must exist once the caller parked on the Call');
        self::assertInstanceOf(PaymentIntentSubject::class, $state->subject);
        self::assertSame('ord-1', $state->subject->reference, 'the Call builds the subject from the caller’s');
        self::assertSame(['awaiting_challenge' => 1], $state->marking);
    }

    public function testBothSagasParkWhileTheChallengeIsOutstanding(): void
    {
        // The shape the mechanism exists for: the caller is parked because its child
        // has not finished, the child because it is waiting on something outside,
        // and neither has anything queued against it.
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        self::assertSame(['awaiting_payment' => 1], $this->repository->load('chk-1')?->marking);
        self::assertSame(
            ['awaiting_challenge' => 1],
            $this->repository->load($this->childOf('chk-1', 'pay', 1))?->marking,
        );
        self::assertSame(['step:place#chk-1', 'step:create#chk-1/pay/1'], $this->log,
            'nothing further is queued for either of them');
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

    // ───────────────── the result ─────────────────

    public function testTheCallFiresWhenTheChildEndsAndCarriesItsFinalSubject(): void
    {
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        $this->runner->signal($this->intent, $this->childOf('chk-1', 'pay', 1), new ChallengePassed('auth-77'));

        self::assertSame([
            'step:place#chk-1',
            'step:create#chk-1/pay/1',
            'intent:passed',
            'step:pay#chk-1',
            'checkout:collected:auth-77',
            'step:settle#chk-1',
            'checkout:settled',
        ], $this->log);
        self::assertNull($this->repository->load('chk-1'), 'the caller ran to the end');
        self::assertNull($this->repository->load($this->childOf('chk-1', 'pay', 1)),
            'and the collected child is gone');
    }

    public function testTheOutcomeIsDataSoTheCallerBranchesOnWhatItCopied(): void
    {
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        $this->runner->signal($this->intent, $this->childOf('chk-1', 'pay', 1), new ChallengeFailed('no funds'));

        self::assertContains('checkout:collected:no funds', $this->log);
        self::assertContains('checkout:abandoned', $this->log,
            'one Call, one target — the outcome is data the caller branches on');
    }

    public function testCollectionArrivesAsAnOrdinaryQueuedStep(): void
    {
        // The child reaches its end inside its own lock. Telling the caller from
        // there would take a second lock while holding the first, which under an
        // inline driver comes straight back for the child. The runner queues the
        // caller's Call instead, once the lock is released — so the collection is
        // just another step, and there is no second entry point to the runner.
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        $this->runner->signal($this->intent, $this->childOf('chk-1', 'pay', 1), new ChallengePassed('auth-77'));

        self::assertContains('step:pay#chk-1', $this->log);
    }

    public function testAnExternalSignalCannotFireACall(): void
    {
        // A Call's wait belongs to its child. Letting a webhook satisfy it would
        // close a wait whose child is still running.
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        $this->expectException(SagaException::class);

        $this->runner->signal($this->checkout, 'chk-1', new PaymentIntentSubject('ord-1', '49.99'));
    }

    public function testAFinishedChildCannotBeAdvancedWhileItWaitsToBeCollected(): void
    {
        // Such a row looks alive and is not: its subject is a result being read, not
        // state being changed. A leftover job or a late signal must not move it.
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));
        $child = $this->childOf('chk-1', 'pay', 1);

        // make the notification fail, so the finished row stays put
        $this->sagas = [PaymentIntentSaga::class => $this->intent];
        try {
            $this->runner->signal($this->intent, $child, new ChallengePassed('auth-77'));
        } catch (Throwable) {
            // the push could not resolve the caller — that is the lost notification
        }

        $finished = $this->repository->load($child);
        self::assertNotNull($finished, 'the finished child is still there, holding its result');

        $this->expectException(SagaException::class);
        $this->expectExceptionMessageMatches('/waiting for the .*Call/');

        $this->runner->signal($this->intent, $child, new ChallengeFailed('too late'));
    }

    // ───────────────── recovery ─────────────────

    public function testRequeueTellsTheCallerAgainWhenTheNotificationWasLost(): void
    {
        // The hole the old arrangement could not close: an answer that was sent and
        // dropped left nothing behind to find. Now the result waits in the child's
        // row, so the sweep can see it and tell the caller again.
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));
        $child = $this->childOf('chk-1', 'pay', 1);

        $this->sagas = [PaymentIntentSaga::class => $this->intent];
        try {
            $this->runner->signal($this->intent, $child, new ChallengePassed('auth-77'));
        } catch (Throwable) {
            // lost notification
        }

        self::assertNotNull($this->repository->load($child));
        self::assertSame(['awaiting_payment' => 1], $this->repository->load('chk-1')?->marking);

        $this->sagas = [CheckoutSaga::class => $this->checkout, PaymentIntentSaga::class => $this->intent];
        $this->runner->requeue($this->checkout, 'chk-1');

        self::assertContains('checkout:collected:auth-77', $this->log);
        self::assertNull($this->repository->load('chk-1'), 'the caller finished after all');
        self::assertNull($this->repository->load($child), 'and the child was retired');
    }

    public function testARedeliveredCollectionDoesNothing(): void
    {
        $this->boot();
        $this->runner->start($this->checkout, 'chk-1', new CheckoutSubject('ord-1', '49.99'));
        $this->runner->signal($this->intent, $this->childOf('chk-1', 'pay', 1), new ChallengePassed('auth-77'));

        $before = $this->log;
        $this->runner->run($this->checkout, 'chk-1', 'pay');

        self::assertSame($before, $this->log, 'there is nothing left to collect');
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

        self::assertNull($this->repository->load($this->childOf('chk-1', 'pay', 1)),
            'a step that never persisted must not have started anything');
    }
}
