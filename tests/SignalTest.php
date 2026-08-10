<?php

declare(strict_types=1);

namespace Techork\Saga\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy;
use Symfony\Component\Workflow\Workflow;
use Techork\Saga\InMemorySagaQueue;
use Techork\Saga\InMemorySagaStateRepository;
use Techork\Saga\InProcessSagaLock;
use Techork\Saga\SagaConcurrencyException;
use Techork\Saga\SagaDefinitionDriftException;
use Techork\Saga\SagaException;
use Techork\Saga\SagaLock;
use Techork\Saga\SagaMarkingStore;
use Techork\Saga\SagaRunner;
use Techork\Saga\Signal;
use Techork\Saga\SignalOutcome;
use Techork\Saga\Tests\Checkout\CheckoutSaga;
use Techork\Saga\Tests\Checkout\CheckoutSubject;
use Techork\Saga\Tests\Checkout\OperatorRelease;
use Techork\Saga\Tests\Checkout\PaymentReceived;

use function sprintf;

/**
 * A Signal is a transition the runner never fires. That one rule is the whole
 * parking mechanism, and these tests pin what it derives.
 */
final class SignalTest extends TestCase
{
    private InMemorySagaStateRepository $repository;

    private InMemorySagaQueue $queue;

    private EventDispatcher $dispatcher;

    private Registry $registry;

    private SagaMarkingStore $markingStore;

    private SagaRunner $runner;

    private CheckoutSaga $saga;

    /** @var list<string> */
    private array $log = [];

    private bool $expired = false;

    protected function setUp(): void
    {
        $this->repository = new InMemorySagaStateRepository;
        $this->queue = new InMemorySagaQueue;
        $this->dispatcher = new EventDispatcher;
        $this->registry = new Registry;
        $this->markingStore = new SagaMarkingStore;
        $this->saga = new CheckoutSaga;
        $this->runner = $this->runnerWith(new InProcessSagaLock);

        $this->registry->addWorkflow(
            new Workflow($this->saga->definition(), $this->markingStore, $this->dispatcher, CheckoutSaga::class),
            new InstanceOfSupportStrategy(CheckoutSubject::class),
        );

        // `expire` is an ordinary transition out of the same place, so the runner
        // DOES queue it — its guard decides whether the deadline has passed.
        $this->dispatcher->addListener(
            sprintf('workflow.%s.guard.expire', CheckoutSaga::class),
            function (GuardEvent $e): void {
                if (! $this->expired) {
                    $e->setBlocked(true);
                }
            },
        );

        // The signal's own listener folds the payload into the subject. This is
        // where data crosses from "arrived" to "durable" — no extra interface.
        $this->dispatcher->addListener(
            sprintf('workflow.%s.transition.payment_received', CheckoutSaga::class),
            function (TransitionEvent $e): void {
                $payload = Signal::payload($e, PaymentReceived::class);

                $subject = $e->getSubject();
                self::assertInstanceOf(CheckoutSubject::class, $subject);
                $subject->card = $payload->card;
                $subject->address = $payload->address;

                $this->log[] = 'PAYMENT '.$payload->card;
            },
        );
        $this->dispatcher->addListener(
            sprintf('workflow.%s.transition.settle', CheckoutSaga::class),
            function (TransitionEvent $e): void {
                $s = $e->getSubject();
                self::assertInstanceOf(CheckoutSubject::class, $s);
                $this->log[] = sprintf('SETTLE %s to %s', $s->amount, (string) $s->card);
            },
        );
        $this->dispatcher->addListener(
            sprintf('workflow.%s.transition.expire', CheckoutSaga::class),
            function (): void {
                $this->log[] = 'EXPIRE';
            },
        );
    }

    public function testTheRunnerNeverQueuesASignalSoTheSagaParks(): void
    {
        $this->runner->start($this->saga, 'chk-1', new CheckoutSubject('49.99'));
        $this->drain();

        $state = $this->repository->load('chk-1');
        self::assertNotNull($state, 'the link must exist while it waits');
        self::assertSame(['awaiting_payment' => 1], $state->marking);
        self::assertTrue($this->queue->isEmpty(), 'payment_received is a Signal; expire is guard-blocked');
        self::assertSame([], $this->log);
    }

    public function testASignalFiresItsTransitionAndCarriesThePayloadToTheListener(): void
    {
        $this->runner->start($this->saga, 'chk-2', new CheckoutSubject('49.99'));
        $this->drain();

        $outcome = $this->runner->signal($this->saga, 'chk-2', new PaymentReceived(
            card: '411111******1111',
            address: 'Riva del Vin 12, Venezia',
        ));

        self::assertSame(SignalOutcome::Applied, $outcome);
        self::assertSame(['PAYMENT 411111******1111'], $this->log);

        // The listener folded it in, so it is durable and later steps see it.
        $state = $this->repository->load('chk-2');
        self::assertSame('411111******1111', $state?->subject->card);
        self::assertSame(['publish', 'payment_received'], $state->history);

        $this->drain();

        self::assertSame(
            ['PAYMENT 411111******1111', 'SETTLE 49.99 to 411111******1111'],
            $this->log,
        );
        self::assertNull($this->repository->load('chk-2'));
    }

    public function testAnOrdinaryTransitionOutOfTheSamePlaceIsStillQueued(): void
    {
        // A mixed exit needs no extra concept: expire is not a Signal, so once its
        // guard passes the runner queues it like anything else.
        $this->expired = true;

        $this->runner->start($this->saga, 'chk-3', new CheckoutSubject('49.99'));
        $this->drain();

        self::assertSame(['EXPIRE'], $this->log);
        self::assertNull($this->repository->load('chk-3'));
    }

    public function testRunRefusesToFireASignalBecauseItWouldCarryNoPayload(): void
    {
        $this->runner->start($this->saga, 'chk-4', new CheckoutSubject('49.99'));
        $this->drain();

        // Drift, not a generic error: a deploy that turns an existing transition
        // into a Signal leaves queued jobs for it, and those must not be treated
        // as business failures and compensated.
        $this->expectException(SagaDefinitionDriftException::class);
        $this->expectExceptionMessage('can only be fired by SagaRunner::signal()');
        $this->runner->run($this->saga, 'chk-4', 'payment_received');
    }

    public function testAPayloadNothingIsWaitingForIsRefusedAndSaysWhatIsAwaited(): void
    {
        $this->runner->start($this->saga, 'chk-5', new CheckoutSubject('49.99'));
        $this->drain();
        $before = $this->repository->load('chk-5')?->version;

        try {
            $this->runner->signal($this->saga, 'chk-5', new OperatorRelease);
            self::fail('a payload no signal accepts must be refused');
        } catch (SagaException $e) {
            self::assertStringContainsString('is not waiting for a', $e->getMessage());
            self::assertStringContainsString('payment_received awaits', $e->getMessage());
            self::assertStringContainsString(PaymentReceived::class, $e->getMessage());
        }

        self::assertSame([], $this->log);
        self::assertSame($before, $this->repository->load('chk-5')?->version, 'nothing was written');
    }

    public function testASpecialisedPayloadStillSatisfiesASignalAwaitingTheBaseType(): void
    {
        $this->runner->start($this->saga, 'chk-6', new CheckoutSubject('49.99'));
        $this->drain();

        $outcome = $this->runner->signal($this->saga, 'chk-6', new ApplePayReceived('4111', 'Riva'));

        self::assertSame(SignalOutcome::Applied, $outcome);
        self::assertSame(['PAYMENT 4111'], $this->log);
    }

    public function testSignallingASagaThatIsNotParkedIsRefused(): void
    {
        // Nothing is enabled that accepts it because the saga has not reached the
        // waiting place yet. The message says so rather than guessing.
        $this->runner->start($this->saga, 'chk-7', new CheckoutSubject('49.99'));
        // deliberately NOT drained: still in 'created'

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('no signal enabled at all');
        $this->runner->signal($this->saga, 'chk-7', new PaymentReceived('4111', 'Riva'));
    }

    public function testSignallingASagaThatNoLongerExistsIsNotAnError(): void
    {
        $outcome = $this->runner->signal($this->saga, 'gone', new PaymentReceived('4111', 'Riva'));

        self::assertSame(SignalOutcome::NotFound, $outcome);
        self::assertTrue($this->queue->isEmpty());
    }

    public function testTwoSimultaneousSignalsCannotBothLand(): void
    {
        // With a lock that provides no mutual exclusion, the compare-and-set is
        // the only thing between the two signals.
        $runner = $this->runnerWith($this->permissiveLock());
        $runner->start($this->saga, 'chk-8', new CheckoutSubject('49.99'));
        while (($m = $this->queue->pop()) !== null) {
            $runner->run($this->saga, $m['id'], $m['transition']);
        }

        $loser = 'never ran';
        $reentered = false;

        $this->dispatcher->addListener(
            sprintf('workflow.%s.transition.payment_received', CheckoutSaga::class),
            function () use ($runner, &$loser, &$reentered): void {
                if ($reentered) {
                    return;
                }
                $reentered = true;

                try {
                    $runner->signal($this->saga, 'chk-8', new PaymentReceived('second', 'a'));
                    $loser = 'BOTH LANDED';
                } catch (SagaConcurrencyException) {
                    $loser = 'rejected';
                }
            },
        );

        try {
            $runner->signal($this->saga, 'chk-8', new PaymentReceived('first', 'a'));
        } catch (SagaConcurrencyException) {
            $loser = 'rejected';
        }

        self::assertSame('rejected', $loser, 'one of the two signals must lose');
    }

    public function testPayloadRefusesAnEventThatCarriesNoSignal(): void
    {
        // An ordinary transition fired by run() has an empty context. Reading the
        // key by hand would be `undefined array key`; this says what went wrong.
        $seen = null;
        $this->dispatcher->addListener(
            sprintf('workflow.%s.transition.expire', CheckoutSaga::class),
            function (TransitionEvent $e) use (&$seen): void {
                try {
                    Signal::payload($e, PaymentReceived::class);
                    $seen = 'RETURNED';
                } catch (SagaException $ex) {
                    $seen = $ex->getMessage();
                }
            },
        );

        $this->expired = true;
        $this->runner->start($this->saga, 'chk-9', new CheckoutSubject('49.99'));
        $this->drain();

        self::assertIsString($seen);
        self::assertStringContainsString('carries no signal payload', $seen);
        self::assertStringContainsString('fired by', $seen);
    }

    public function testPayloadRefusesTheWrongType(): void
    {
        $seen = null;
        $this->dispatcher->addListener(
            sprintf('workflow.%s.transition.payment_received', CheckoutSaga::class),
            function (TransitionEvent $e) use (&$seen): void {
                try {
                    Signal::payload($e, OperatorRelease::class);
                    $seen = 'RETURNED';
                } catch (SagaException $ex) {
                    $seen = $ex->getMessage();
                }
            },
        );

        $this->runner->start($this->saga, 'chk-10', new CheckoutSubject('49.99'));
        $this->drain();
        $this->runner->signal($this->saga, 'chk-10', new PaymentReceived('4111', 'Riva'));

        self::assertIsString($seen);
        self::assertStringContainsString('was signalled with', $seen);
        self::assertStringContainsString(OperatorRelease::class, $seen);
    }

    public function testPayloadIsReadableFromEveryEventOfTheSameApply(): void
    {
        // leave / enter / entered / completed / announce all carry the context, so
        // a listener anywhere in the apply can read the signal — but nothing
        // outside that apply can, which is why the data has to reach the subject.
        $seen = [];
        foreach ([
            'entered.captured',
            'completed.payment_received',
            'announce.settle',
        ] as $event) {
            $this->dispatcher->addListener(
                sprintf('workflow.%s.%s', CheckoutSaga::class, $event),
                function (object $e) use (&$seen, $event): void {
                    /** @var \Symfony\Component\Workflow\Event\CompletedEvent $e */
                    $seen[$event] = Signal::payload($e, PaymentReceived::class)->card;
                },
            );
        }

        $this->runner->start($this->saga, 'chk-11', new CheckoutSubject('49.99'));
        $this->drain();
        $this->runner->signal($this->saga, 'chk-11', new PaymentReceived('4111', 'Riva'));

        self::assertSame([
            'entered.captured' => '4111',
            'completed.payment_received' => '4111',
            'announce.settle' => '4111',
        ], $seen);
    }

    // ───────────────── helpers ─────────────────

    private function runnerWith(SagaLock $lock): SagaRunner
    {
        return new SagaRunner(
            $this->repository,
            $this->queue,
            $this->dispatcher,
            $this->registry,
            $this->markingStore,
            $lock,
        );
    }

    private function permissiveLock(): SagaLock
    {
        return new class implements SagaLock {
            public function withLock(string $sagaId, callable $work): mixed
            {
                return $work();
            }
        };
    }

    private function drain(int $guard = 20): void
    {
        $n = 0;
        while (($msg = $this->queue->pop()) !== null) {
            self::assertLessThan($guard, $n++, 'runaway fan-out');
            $this->runner->run($this->saga, $msg['id'], $msg['transition']);
        }
    }
}

/** A specialised payment, to pin that accepts() is instanceof and not equality. */
final class ApplePayReceived extends PaymentReceived
{
}
