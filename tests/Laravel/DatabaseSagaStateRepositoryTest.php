<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel;

use Carbon\WrapperClock;
use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Techork\Saga\Laravel\DatabaseSagaStateRepository;
use Techork\Saga\SagaException;
use Techork\Saga\SagaState;
use Techork\Saga\Tests\TestSubject;

final class DatabaseSagaStateRepositoryTest extends TestCase
{
    private ConnectionInterface $connection;

    private DatabaseSagaStateRepository $repository;

    protected function setUp(): void
    {
        $capsule = new Capsule;
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $this->connection = $capsule->getConnection();
        $this->connection->getSchemaBuilder()->create('sagas', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->json('marking');
            $t->longText('subject');
            $t->json('history');
            $t->unsignedInteger('version');
            $t->timestamps();
        });

        $this->repository = new DatabaseSagaStateRepository($this->connection, new WrapperClock(new DateTimeImmutable));
    }

    public function testLoadReturnsNullWhenMissing(): void
    {
        self::assertNull($this->repository->load('nope'));
    }

    public function testInitialSaveInsertsRow(): void
    {
        $subject = new TestSubject;
        $subject->path = 'left';
        $subject->counter = 42;

        $state = new SagaState(
            id: 's1',
            marking: ['start' => 1],
            subject: $subject,
            history: [],
            version: 1,
        );
        $this->repository->save($state);

        $loaded = $this->repository->load('s1');
        self::assertNotNull($loaded);
        self::assertSame('s1', $loaded->id);
        self::assertSame(['start' => 1], $loaded->marking);
        self::assertInstanceOf(TestSubject::class, $loaded->subject);
        self::assertSame('left', $loaded->subject->path);
        self::assertSame(42, $loaded->subject->counter);
        self::assertSame([], $loaded->history);
        self::assertSame(1, $loaded->version);
    }

    public function testOptimisticUpdateBumpsVersion(): void
    {
        $first = new TestSubject;
        $second = new TestSubject;
        $second->counter = 2;

        $this->repository->save(new SagaState('s1', ['a' => 1], $first, [], 1));
        $this->repository->save(new SagaState('s1', ['b' => 1], $second, ['t'], 2));

        $loaded = $this->repository->load('s1');
        self::assertSame(['b' => 1], $loaded?->marking);
        self::assertSame(2, $loaded->version);
        self::assertSame(['t'], $loaded->history);
        self::assertInstanceOf(TestSubject::class, $loaded->subject);
        self::assertSame(2, $loaded->subject->counter);
    }

    public function testConcurrentUpdateWithStaleVersionFails(): void
    {
        $this->repository->save(new SagaState('s1', ['a' => 1], new TestSubject, [], 1));
        $this->repository->save(new SagaState('s1', ['b' => 1], new TestSubject, ['t'], 2));

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('Optimistic lock failure');
        $this->repository->save(new SagaState('s1', ['c' => 1], new TestSubject, ['t'], 2));
    }

    public function testDeleteRemovesRow(): void
    {
        $this->repository->save(new SagaState('s1', ['a' => 1], new TestSubject, [], 1));
        $this->repository->delete('s1');

        self::assertNull($this->repository->load('s1'));
    }

    public function testUnicodeAndNestedStructuresRoundTrip(): void
    {
        $subject = new TestSubject;
        $subject->path = 'Алексей';
        $subject->branchLog = ['A-1', 'B-2', 'Δ'];

        $this->repository->save(new SagaState('s1', ['start' => 1], $subject, [], 1));

        $loaded = $this->repository->load('s1');
        self::assertInstanceOf(TestSubject::class, $loaded?->subject);
        self::assertSame('Алексей', $loaded->subject->path);
        self::assertSame(['A-1', 'B-2', 'Δ'], $loaded->subject->branchLog);
    }
}
