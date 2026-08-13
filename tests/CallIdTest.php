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
use Techork\Saga\Call;
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
use Techork\Saga\Tests\Call\CheckoutSubject;
use Techork\Saga\Tests\Call\CollidingCallSaga;
use Techork\Saga\Tests\Call\NamedCallSaga;
use Techork\Saga\Tests\Call\PaymentAuthorized;
use Techork\Saga\Tests\Call\PaymentDeclined;
use Techork\Saga\Tests\Call\PaymentIntentSaga;
use Techork\Saga\Tests\Call\PaymentIntentSubject;

use function bin2hex;
use function random_bytes;
use function sprintf;

/**
 * A Call may name its own children.
 *
 * The runner's default id is opaque, which is fine until something outside has
 * to find the child — an endpoint capturing a payment, a provider's webhook.
 * What must survive the change is that the id stays DERIVED: the same inputs
 * give the same id, so a launch lost before it happened can still be recreated.
 */
final class CallIdTest extends TestCase
{
    private InMemorySagaStateRepository $repository;

    private EventDispatcher $dispatcher;

    private Registry $registry;

    private SagaMarkingStore $markingStore;

    private SagaRunner $runner;

    private PaymentIntentSaga $intent;

    private bool $allowRetry = false;

    protected function setUp(): void
    {
        $this->repository = new InMemorySagaStateRepository;
        $this->dispatcher = new EventDispatcher;
        $this->registry = new Registry;
        $this->markingStore = new SagaMarkingStore;
        $this->intent = new PaymentIntentSaga;
    }

    private function boot(Saga $parent): void
    {
        $queue = new class implements SagaQueue
        {
            public ?SagaRunner $runner = null;

            /** @var array<class-string<Saga>, Saga> */
            public array $sagas = [];

            public function push(string $sagaClass, string $sagaId, string $transition, int $delaySeconds = 0): void
            {
                $this->runner?->run($this->sagas[$sagaClass], $sagaId, $transition);
            }
        };

        $locator = new class($parent, $this->intent) implements SagaLocator
        {
            public function __construct(private Saga $parent, private PaymentIntentSaga $intent) {}

            public function get(string $sagaClass): Saga
            {
                return $sagaClass === PaymentIntentSaga::class ? $this->intent : $this->parent;
            }
        };

        $this->runner = new SagaRunner(
            $this->repository,
            $queue,
            $this->dispatcher,
            $this->registry,
            $this->markingStore,
            new InProcessSagaLock,
            $locator,
        );
        $queue->runner = $this->runner;
        $queue->sagas = [$parent::class => $parent, PaymentIntentSaga::class => $this->intent];

        $this->registry->addWorkflow(
            new Workflow($parent->definition(), $this->markingStore, $this->dispatcher, $parent::class),
            new InstanceOfSupportStrategy(CheckoutSubject::class),
        );
        $this->registry->addWorkflow(
            new Workflow($this->intent->definition(), $this->markingStore, $this->dispatcher, PaymentIntentSaga::class),
            new InstanceOfSupportStrategy(PaymentIntentSubject::class),
        );

        $this->dispatcher->addListener(sprintf('workflow.%s.guard.retry', $parent::class),
            function (GuardEvent $e): void {
                if (! $this->allowRetry) {
                    $e->setBlocked(true);
                }
            });
        $this->dispatcher->addListener(sprintf('workflow.%s.transition.pay', $parent::class),
            static fn (TransitionEvent $e) => $e->getSubject()->authCode
                = Signal::payload($e, PaymentAuthorized::class)->authCode);
        $this->dispatcher->addListener(sprintf('workflow.%s.transition.challenge_passed', PaymentIntentSaga::class),
            static fn (TransitionEvent $e) => SagaRunner::reply(
                $e,
                new PaymentAuthorized(Signal::payload($e, ChallengePassed::class)->authCode),
            ));
        $this->dispatcher->addListener(sprintf('workflow.%s.transition.challenge_failed', PaymentIntentSaga::class),
            static fn (TransitionEvent $e) => SagaRunner::reply(
                $e,
                new PaymentDeclined(Signal::payload($e, ChallengeFailed::class)->reason),
            ));
    }

    public function testTheCallsOwnRuleNamesTheChild(): void
    {
        $saga = new NamedCallSaga;
        $this->boot($saga);

        $this->runner->start($saga, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        self::assertNotNull($this->repository->load('pi-ord-1-1'),
            'something outside must be able to address the child by a name it knows');
        self::assertNull($this->repository->load('chk-1/pay/1'), 'the default id is not used as well');
    }

    public function testANamedChildStillAnswersItsCaller(): void
    {
        $saga = new NamedCallSaga;
        $this->boot($saga);
        $this->runner->start($saga, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        $this->runner->signal($this->intent, 'pi-ord-1-1', new ChallengePassed('auth-9'));

        self::assertNull($this->repository->load('chk-1'), 'the checkout ran on to the end');
    }

    public function testAGeneratedChildIdIsRecordedSoRecoveryDoesNotSpawnDuplicates(): void
    {
        // A real id rule generates — uuid7, a provider's reference — and a
        // generated value cannot be recomputed. Recovery that recomputes would
        // therefore never find the child it is looking for and would create
        // another one every sweep: four payment intents for one payment.
        $saga = new class implements Saga
        {
            public function definition(): Definition
            {
                return new Definition(['new', 'paying', 'paid'], [
                    new Transition('open', 'new', 'paying'),
                    new Call('pay', 'paying', 'paid',
                        runs: PaymentIntentSaga::class, awaits: PaymentAuthorized::class,
                        subject: static fn (CheckoutSubject $s): object
                            => new PaymentIntentSubject($s->orderId, $s->amount),
                        id: static fn (PaymentIntentSubject $s, int $attempt): string
                            => 'pi_'.bin2hex(random_bytes(8))),
                ], ['new']);
            }
        };
        $this->boot($saga);

        // every child announces itself as it is created, so no reflection is
        // needed to count them
        $born = [];
        $this->dispatcher->addListener(sprintf('workflow.%s.transition.create', PaymentIntentSaga::class),
            static function (TransitionEvent $e) use (&$born): void {
                $born[] = SagaRunner::sagaId($e);
            });

        $this->runner->start($saga, 'chk-g', new CheckoutSubject('ord-g', '49.99'));
        self::assertCount(1, $born, 'one child at launch');

        $this->runner->requeue($saga, 'chk-g');
        $this->runner->requeue($saga, 'chk-g');

        self::assertCount(1, $born,
            'the sweep must find the child it already has, not generate another id');
    }

    public function testALaterStepCanReadTheGeneratedChildId(): void
    {
        // The point of writing the id down: the step that captures the payment,
        // or an endpoint answering a webhook about it, needs to name the child —
        // and that is a different step, which is a different process. Nothing has
        // to be copied into the subject on the way past.
        $saga = new NamedCallSaga;
        $this->boot($saga);

        $seen = null;
        $this->dispatcher->addListener(sprintf('workflow.%s.transition.settle', NamedCallSaga::class),
            static function (TransitionEvent $e) use (&$seen): void {
                $seen = SagaRunner::childId($e, 'pay');
            });

        $this->runner->start($saga, 'chk-1', new CheckoutSubject('ord-1', '49.99'));
        $this->runner->signal($this->intent, 'pi-ord-1-1', new ChallengePassed('auth-9'));

        self::assertSame('pi-ord-1-1', $seen, 'the step after the launch reads the id the engine handed out');
    }

    public function testABusinessRetryGetsAFreshGeneratedIdWhileATechnicalOneDoesNot(): void
    {
        // The distinction the engine has to respect. A technical retry is the same
        // wait redriven and must reuse the child; a business retry is a new wait —
        // the intent declined and the saga decided to pay again — and must have a
        // new one, or the second attempt collides with the first at the provider.
        // Attempts tell them apart: only a business retry adds to history.
        $saga = new class implements Saga
        {
            public function definition(): Definition
            {
                return new Definition(['new', 'paying', 'paid', 'declined'], [
                    new Transition('open', 'new', 'paying'),
                    new Call('pay', 'paying', 'paid',
                        runs: PaymentIntentSaga::class, awaits: PaymentAuthorized::class,
                        subject: static fn (CheckoutSubject $s): object
                            => new PaymentIntentSubject($s->orderId, $s->amount),
                        id: static fn (PaymentIntentSubject $s, int $attempt): string
                            => 'pi_'.bin2hex(random_bytes(8))),
                    new Signal('payment_declined', 'paying', 'declined', awaits: PaymentDeclined::class),
                    new Transition('retry', 'declined', 'paying'),
                ], ['new']);
            }
        };
        $this->boot($saga);

        $born = [];
        $this->dispatcher->addListener(sprintf('workflow.%s.transition.create', PaymentIntentSaga::class),
            static function (TransitionEvent $e) use (&$born): void {
                $born[] = SagaRunner::sagaId($e);
            });

        $this->runner->start($saga, 'chk-b', new CheckoutSubject('ord-b', '49.99'));
        self::assertCount(1, $born);
        $firstChild = $born[0];

        // technical: the sweep redrives the same wait
        $this->runner->requeue($saga, 'chk-b');
        self::assertCount(1, $born, 'a technical retry reuses the child');

        // business: the intent declines and the saga pays again
        $this->runner->signal($this->intent, $firstChild, new ChallengeFailed('no funds'));
        $this->allowRetry = true;
        $this->runner->run($saga, 'chk-b', 'retry');

        self::assertCount(2, $born, 'a business retry gets a child of its own');
        self::assertNotSame($firstChild, $born[1], 'and a freshly generated id');
    }


    public function testRequeueRecomputesTheSameNameAndRecreatesAMissingChild(): void
    {
        // The property that must survive naming the child yourself: the rule is a
        // pure function of the child's subject, so the sweep lands on the same id.
        $saga = new NamedCallSaga;
        $this->boot($saga);
        $this->runner->start($saga, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        $this->repository->delete('pi-ord-1-1');
        self::assertNull($this->repository->load('pi-ord-1-1'));

        $this->runner->requeue($saga, 'chk-1');

        self::assertNotNull($this->repository->load('pi-ord-1-1'));
    }

    public function testASecondAttemptGetsItsOwnNamedChild(): void
    {
        $saga = new NamedCallSaga;
        $this->boot($saga);
        $this->runner->start($saga, 'chk-1', new CheckoutSubject('ord-1', '49.99'));
        $this->runner->signal($this->intent, 'pi-ord-1-1', new ChallengeFailed('no funds'));

        $this->allowRetry = true;
        $this->runner->run($saga, 'chk-1', 'retry');

        self::assertNotNull($this->repository->load('pi-ord-1-2'), 'the rule varies by attempt, so the retry is a new child');
    }

    public function testARuleThatIgnoresTheAttemptIsRefusedWhileTheFirstChildIsStillRunning(): void
    {
        // The trap in naming children yourself, and it only springs when the
        // earlier child OUTLIVES the retry — a caller that gave up on a deadline
        // rather than on an answer. Reusing the id once the first child is gone
        // is harmless and stays allowed.
        //
        // Swallowing this would be the worst outcome available: the retry would
        // adopt the first attempt's child, nothing would be launched, and that
        // child's answer would arrive for an attempt that no longer exists.
        $saga = new CollidingCallSaga;
        $this->boot($saga);
        $this->runner->start($saga, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        // the deadline passes: the caller moves on while the child still runs
        $this->runner->signal($saga, 'chk-1', new PaymentDeclined('deadline'));
        self::assertNotNull($this->repository->load('pi-ord-1'), 'the first child is still parked');

        $this->allowRetry = true;

        $this->expectException(SagaException::class);
        $this->expectExceptionMessageMatches('/must vary by its \$attempt argument/');

        $this->runner->run($saga, 'chk-1', 'retry');
    }

    public function testReusingTheIdOfAChildThatHasFinishedIsAllowed(): void
    {
        $saga = new CollidingCallSaga;
        $this->boot($saga);
        $this->runner->start($saga, 'chk-1', new CheckoutSubject('ord-1', '49.99'));

        // the child answers and ends, so its row is gone before the retry
        $this->runner->signal($this->intent, 'pi-ord-1', new ChallengeFailed('no funds'));
        self::assertNull($this->repository->load('pi-ord-1'));

        $this->allowRetry = true;
        $this->runner->run($saga, 'chk-1', 'retry');

        self::assertNotNull($this->repository->load('pi-ord-1'), 'a fresh child under the same name');
    }
}
