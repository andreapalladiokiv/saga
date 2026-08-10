<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Techork\Saga\Laravel\LaravelSagaQueue;
use Techork\Saga\Laravel\SagaStepJob;
use Techork\Saga\Tests\Laravel\Fixtures\OrderSaga;
use Techork\Saga\Tests\Laravel\Fixtures\ProtectedRoutingSaga;
use Techork\Saga\Tests\Laravel\Fixtures\RecordingBusDispatcher;
use Techork\Saga\Tests\Laravel\Fixtures\RoutedSaga;

final class LaravelSagaQueueTest extends TestCase
{
    private Container $container;

    private RecordingBusDispatcher $bus;

    protected function setUp(): void
    {
        $this->container = new Container;
        Container::setInstance($this->container);
        $this->bus = new RecordingBusDispatcher;
    }

    protected function tearDown(): void
    {
        Container::setInstance();
    }

    public function testPushDispatchesAJobCarryingTheStep(): void
    {
        $this->queue()->push(OrderSaga::class, 'ord-1', 'charge_card');

        self::assertCount(1, $this->bus->dispatched);
        $job = $this->bus->dispatched[0];
        self::assertInstanceOf(SagaStepJob::class, $job);
        self::assertSame(OrderSaga::class, $job->sagaClass);
        self::assertSame('ord-1', $job->sagaId);
        self::assertSame('charge_card', $job->transition);
    }

    public function testJobsAreMarkedAfterCommitSoTheyCannotOutrunTheirOwnTransaction(): void
    {
        // start() inside DB::transaction() saves the row and then pushes. Without
        // afterCommit the worker can dequeue and run the step before the INSERT
        // commits; run() then finds no state and returns silently by design, so
        // the saga sits at version 1 forever with nothing queued and no error.
        $this->queue()->push(OrderSaga::class, 'ord-1', 'charge_card');

        self::assertTrue($this->bus->dispatched[0]->afterCommit);
    }

    public function testAfterCommitCanBeTurnedOffForApplicationsThatDispatchOutsideTransactions(): void
    {
        $queue = new LaravelSagaQueue($this->bus, $this->container, afterCommit: false);
        $queue->push(OrderSaga::class, 'ord-1', 'charge_card');

        self::assertNotTrue($this->bus->dispatched[0]->afterCommit);
    }

    public function testADelayIsAppliedWhenAsked(): void
    {
        $this->queue()->push(OrderSaga::class, 'ord-1', 'charge_card', 30);

        self::assertSame(30, $this->bus->dispatched[0]->delay);
    }

    public function testRoutingDeclaredThroughTheInterfaceIsApplied(): void
    {
        $this->queue()->push(RoutedSaga::class, 'ord-1', 'go');

        $job = $this->bus->dispatched[0];
        self::assertSame('redis-long', $job->connection);
        self::assertSame('shipping', $job->queue);
    }

    public function testRoutingOnANonPublicPropertyIsNoLongerSilentlyIgnored(): void
    {
        // `$saga->queue ?? null` conflates "no such property" with "the property
        // is protected", so `protected string $queue = 'shipping'` routed to the
        // default queue with no warning and a dedicated worker sat idle.
        // Expressing routing as an interface makes the mistake impossible.
        $this->queue()->push(ProtectedRoutingSaga::class, 'ord-1', 'go');

        $job = $this->bus->dispatched[0];
        self::assertNull($job->queue, 'a saga that does not implement SagaRouting gets the defaults');
    }

    public function testConstructorDefaultsApplyWhenTheSagaDeclaresNoRouting(): void
    {
        $queue = new LaravelSagaQueue($this->bus, $this->container, 'sqs', 'sagas');
        $queue->push(OrderSaga::class, 'ord-1', 'charge_card');

        $job = $this->bus->dispatched[0];
        self::assertSame('sqs', $job->connection);
        self::assertSame('sagas', $job->queue);
    }

    public function testTheSagaIsResolvedOncePerPushNotThreeTimes(): void
    {
        $made = 0;
        $this->container->bind(OrderSaga::class, function () use (&$made): OrderSaga {
            $made++;

            return new OrderSaga;
        });

        $this->queue()->push(OrderSaga::class, 'ord-1', 'charge_card');

        self::assertSame(1, $made, 'a push must not rebuild the saga graph repeatedly');
    }

    private function queue(): LaravelSagaQueue
    {
        return new LaravelSagaQueue($this->bus, $this->container);
    }
}
