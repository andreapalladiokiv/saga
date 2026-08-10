<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy;
use Symfony\Component\Workflow\Workflow;
use Techork\Saga\InMemorySagaQueue;
use Techork\Saga\InMemorySagaStateRepository;
use Techork\Saga\InProcessSagaLock;
use Techork\Saga\Laravel\SagaStepJob;
use Techork\Saga\SagaConcurrencyException;
use Techork\Saga\SagaDefinitionDriftException;
use Techork\Saga\SagaFailedException;
use Techork\Saga\SagaMarkingStore;
use Techork\Saga\SagaRunner;
use Techork\Saga\SagaStateRepository;
use Techork\Saga\Tests\Laravel\Fixtures\OrderSaga;
use Techork\Saga\Tests\Laravel\Fixtures\RecordingBusDispatcher;
use Techork\Saga\Tests\TestSubject;
use Throwable;

use function sprintf;

/**
 * The Laravel wiring layer, driven through a real Illuminate container.
 *
 * These tests exist because everything the runner tests prove is reached via
 * hand-wired Symfony objects that no shipped code constructs — the job is the
 * only path a real consumer executes, and it is where a lost optimistic lock
 * used to destroy a healthy saga.
 */
final class SagaStepJobTest extends TestCase
{
    private Container $container;

    private RecordingBusDispatcher $bus;

    private InProcessSagaLock $lock;

    private InMemorySagaStateRepository $repository;

    private EventDispatcher $events;

    private SagaRunner $runner;

    private OrderSaga $saga;

    protected function setUp(): void
    {
        $this->container = new Container;
        Container::setInstance($this->container);

        $this->bus = new RecordingBusDispatcher;
        $this->container->instance(BusDispatcher::class, $this->bus);

        $this->lock = new InProcessSagaLock;
        $this->repository = new InMemorySagaStateRepository;
        $this->events = new EventDispatcher;
        $markingStore = new SagaMarkingStore;
        $registry = new Registry;

        $this->saga = new OrderSaga;
        $this->container->instance(OrderSaga::class, $this->saga);

        $registry->addWorkflow(
            new Workflow($this->saga->definition(), $markingStore, $this->events, OrderSaga::class),
            new InstanceOfSupportStrategy(TestSubject::class),
        );

        $this->runner = new SagaRunner(
            $this->repository,
            new InMemorySagaQueue,
            $this->events,
            $registry,
            $markingStore,
            $this->lock,
        );
        $this->container->instance(SagaRunner::class, $this->runner);
    }

    protected function tearDown(): void
    {
        Container::setInstance();
    }

    // ───────────── a lost persist race must never destroy the saga ─────────────

    public function testConcurrencyConflictIsRetriedOnItsOwnBudgetInsteadOfFailingTheJob(): void
    {
        $runner = $this->runnerThrowing(SagaConcurrencyException::versionMismatch('ord-1', 2));

        $job = new SagaStepJob(OrderSaga::class, 'ord-1', 'charge_card');
        $job->onConnection('redis')->onQueue('sagas');

        // Must NOT throw: throwing marks the job failed, and failed() is what
        // compensates and deletes the saga.
        $job($runner, $this->container);

        self::assertCount(1, $this->bus->dispatched);

        $retry = $this->bus->dispatched[0];
        self::assertInstanceOf(SagaStepJob::class, $retry);
        self::assertSame('charge_card', $retry->transition);
        self::assertSame('ord-1', $retry->sagaId);
        self::assertSame(2, $retry->concurrencyAttempt, 'persist-race round must advance');
        self::assertSame('redis', $retry->connection, 'routing must survive the retry');
        self::assertSame('sagas', $retry->queue);
        self::assertNotNull($retry->delay, 'retry must wait for the winner to be visible');
    }

    public function testConcurrencyRetriesAreBoundedAndDoNotConsumeTheBusinessRetryBudget(): void
    {
        $runner = $this->runnerThrowing(SagaConcurrencyException::versionMismatch('ord-1', 2));

        // tries() governs business failures only, and stays at the conservative
        // default — the persist-race budget is separate and larger.
        self::assertSame(1, (new SagaStepJob(OrderSaga::class, 'ord-1', 'charge_card'))->tries());

        $rounds = 0;
        $job = new SagaStepJob(OrderSaga::class, 'ord-1', 'charge_card');

        while (true) {
            try {
                $job($runner, $this->container);
            } catch (SagaConcurrencyException) {
                break;
            }
            $rounds++;
            self::assertLessThan(50, $rounds, 'concurrency retries must terminate');
            $job = $this->bus->dispatched[$rounds - 1];
        }

        self::assertSame(11, $rounds, 'bounded re-dispatch, then the exception is allowed out');
    }

    public function testFailedDoesNotCompensateWhenTheCauseIsALostPersistRace(): void
    {
        $compensated = [];
        $this->onCompensation('fork', $compensated);
        $this->onCompensation('reserve_stock', $compensated);

        $this->startAndAdvance();
        $before = $this->repository->load('ord-1');
        self::assertNotNull($before);
        self::assertSame(['fork', 'reserve_stock'], $before->history);

        // The losing worker's job exhausts its attempts on lock conflicts.
        (new SagaStepJob(OrderSaga::class, 'ord-1', 'charge_card'))
            ->failed(SagaConcurrencyException::versionMismatch('ord-1', 2));

        self::assertSame([], $compensated, 'a healthy saga must not be rolled back over a race');
        self::assertNotNull($this->repository->load('ord-1'), 'the winner still owns this saga');
        self::assertSame(['fork', 'reserve_stock'], $this->repository->load('ord-1')->history);
    }

    public function testFailedStillCompensatesAGenuineStepFailure(): void
    {
        $compensated = [];
        $this->onCompensation('fork', $compensated);
        $this->onCompensation('reserve_stock', $compensated);

        $this->startAndAdvance();

        (new SagaStepJob(OrderSaga::class, 'ord-1', 'charge_card'))
            ->failed(new RuntimeException('payment gateway rejected the card'));

        self::assertSame(['reserve_stock', 'fork'], $compensated, 'reverse order');
        self::assertNull($this->repository->load('ord-1'));
    }

    // ───────────── the failing step, and rollbacks that themselves fail ─────────────

    public function testFailedCompensatesItsOwnTransitionWhichIsAbsentFromHistory(): void
    {
        // `charge_card` threw, so it never persisted and is not in history — yet
        // it is the one step certain to have run. The job knows its own
        // transition and must name it.
        $compensated = [];
        foreach (['fork', 'reserve_stock', 'charge_card'] as $t) {
            $this->onCompensation($t, $compensated);
        }

        $this->startAndAdvance();
        self::assertSame(['fork', 'reserve_stock'], $this->repository->load('ord-1')?->history);

        (new SagaStepJob(OrderSaga::class, 'ord-1', 'charge_card'))
            ->failed(new RuntimeException('captured the card, then timed out'));

        self::assertSame(['charge_card', 'reserve_stock', 'fork'], $compensated);
        self::assertNull($this->repository->load('ord-1'));
    }

    public function testFailedSurfacesCompensationFailuresInsteadOfDiscardingThem(): void
    {
        $this->events->addListener(
            sprintf('saga.%s.compensate.reserve_stock', OrderSaga::class),
            static function (): void {
                throw new RuntimeException('refund endpoint returned 503');
            },
        );

        $this->startAndAdvance();

        $cause = new RuntimeException('payment gateway rejected the card');
        $job = new SagaStepJob(OrderSaga::class, 'ord-1', 'charge_card');

        try {
            $job->failed($cause);
            self::fail('a failed rollback must not be swallowed');
        } catch (SagaFailedException $e) {
            self::assertSame($cause, $e->cause);
            self::assertCount(1, $e->compensationErrors);
            self::assertSame('refund endpoint returned 503', $e->compensationErrors[0]->getMessage());
            self::assertStringContainsString('could not be rolled back', $e->getMessage());
        }

        // The row is the only record of what still needs undoing.
        $state = $this->repository->load('ord-1');
        self::assertNotNull($state, 'a failed rollback must keep the saga');
        self::assertSame(
            ['fork', 'reserve_stock', \Techork\Saga\SagaRunner::ROLLBACK_FAILED],
            $state->history,
        );
    }

    public function testFailedLogsCompensationFailuresAtCritical(): void
    {
        $records = [];
        $this->container->instance(LoggerInterface::class, new class($records) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array}> $records */
            public function __construct(private array &$records) {}

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        });

        $this->events->addListener(
            sprintf('saga.%s.compensate.reserve_stock', OrderSaga::class),
            static function (): void {
                throw new RuntimeException('refund endpoint returned 503');
            },
        );

        $this->startAndAdvance();

        try {
            (new SagaStepJob(OrderSaga::class, 'ord-1', 'charge_card'))->failed(new RuntimeException('nope'));
        } catch (SagaFailedException) {
            // expected
        }

        self::assertCount(1, $records);
        self::assertSame(LogLevel::CRITICAL, $records[0]['level']);
        self::assertSame('Saga compensation failed', $records[0]['message']);
        self::assertSame('ord-1', $records[0]['context']['saga_id']);
        self::assertSame('charge_card', $records[0]['context']['transition']);
    }

    public function testFailedWorksWithoutALoggerBound(): void
    {
        self::assertFalse($this->container->bound(LoggerInterface::class));

        $this->events->addListener(
            sprintf('saga.%s.compensate.reserve_stock', OrderSaga::class),
            static function (): void {
                throw new RuntimeException('refund endpoint returned 503');
            },
        );

        $this->startAndAdvance();

        $this->expectException(SagaFailedException::class);
        (new SagaStepJob(OrderSaga::class, 'ord-1', 'charge_card'))->failed(new RuntimeException('nope'));
    }

    public function testFailedDoesNotCompensateWhenTheCodeNoLongerFitsTheSaga(): void
    {
        // SagaDefinitionDriftException exists precisely so a deploy does not roll
        // sagas back: renaming a place strands every saga parked in it, and
        // compensating 400 of them means issuing 400 refunds over a one-word
        // rename. The exception was being raised and then ignored here.
        $compensated = [];
        $this->onCompensation('fork', $compensated);
        $this->onCompensation('reserve_stock', $compensated);

        $this->startAndAdvance();

        (new SagaStepJob(OrderSaga::class, 'ord-1', 'charge_card'))->failed(
            SagaDefinitionDriftException::unknownPlace('ord-1', 'pending_signal', ['a', 'b']),
        );

        self::assertSame([], $compensated, 'a deploy must not trigger a rollback');
        self::assertNotNull($this->repository->load('ord-1'), 'and must not delete the saga');
    }

    public function testFailedLogsDriftSoItIsNotSilentlyDropped(): void
    {
        $records = [];
        $this->container->instance(LoggerInterface::class, new class($records) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array}> $records */
            public function __construct(private array &$records) {}

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        });

        $this->startAndAdvance();

        (new SagaStepJob(OrderSaga::class, 'ord-1', 'charge_card'))->failed(
            SagaDefinitionDriftException::unknownTransition('ord-1', 'gone', ['a']),
        );

        self::assertCount(1, $records);
        self::assertSame(LogLevel::CRITICAL, $records[0]['level']);
        self::assertStringContainsString('needs attention', $records[0]['message']);
        self::assertSame('ord-1', $records[0]['context']['saga_id']);
    }

    // ───────────── container plumbing (no laravel/framework present) ─────────────

    public function testTriesAndBackoffResolveWithoutTheGlobalAppHelper(): void
    {
        // laravel/framework — which ships app() — is not a dependency of this
        // package. These must work with illuminate/container alone.
        self::assertFalse(\function_exists('app'), 'guard: this suite must not have the helper loaded');

        $job = new SagaStepJob(OrderSaga::class, 'ord-1', 'charge_card');

        self::assertSame(1, $job->tries());
        self::assertNull($job->backoff(), 'null, not [], so Laravel falls back to the worker default');
    }

    public function testDisplayNameIdentifiesTheStep(): void
    {
        self::assertSame(
            sprintf('Saga[%s::charge_card#ord-1]', OrderSaga::class),
            (new SagaStepJob(OrderSaga::class, 'ord-1', 'charge_card'))->displayName(),
        );
    }

    // ───────────────── helpers ─────────────────

    /** Drives the saga to marking {a_done, b} with history [fork, reserve_stock]. */
    private function startAndAdvance(): void
    {
        $this->runner->start($this->saga, 'ord-1', new TestSubject);
        $this->runner->run($this->saga, 'ord-1', 'fork');
        $this->runner->run($this->saga, 'ord-1', 'reserve_stock');
    }

    /** @param list<string> $log */
    private function onCompensation(string $transition, array &$log): void
    {
        $this->events->addListener(
            sprintf('saga.%s.compensate.%s', OrderSaga::class, $transition),
            static function () use (&$log, $transition): void {
                $log[] = $transition;
            },
        );
    }

    /** A runner whose run() always fails the way a lost compare-and-set does. */
    private function runnerThrowing(Throwable $e): SagaRunner
    {
        $repository = new class($e) implements SagaStateRepository {
            public function __construct(private Throwable $e) {}

            public function load(string $id): ?\Techork\Saga\SagaState
            {
                throw $this->e;
            }

            public function save(\Techork\Saga\SagaState $state): void {}

            public function delete(string $id): void {}
        };

        return new SagaRunner(
            $repository,
            new InMemorySagaQueue,
            $this->events,
            new Registry,
            new SagaMarkingStore,
            $this->lock,
        );
    }
}
