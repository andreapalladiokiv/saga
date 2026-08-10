<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Techork\Saga\Laravel\DatabaseSagaStateRepository;
use Techork\Saga\SagaConcurrencyException;
use Techork\Saga\SagaException;
use Techork\Saga\SagaState;
use Techork\Saga\PlainSubjectCodec;
use Techork\Saga\SystemClock;
use Techork\Saga\Tests\SubjectWithSecrets;
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

        // Build the table from the PUBLISHED migration rather than a hand-rolled
        // copy. A test schema that drifts from the stub hides exactly the class
        // of bug the stub can introduce — a wrong column type, a missing column.
        $container = new Container;
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        $migration = require __DIR__.'/../../database/migrations/create_sagas_table.php.stub';
        $migration->up();

        $this->connection = $capsule->getConnection();
        $this->repository = new DatabaseSagaStateRepository($this->connection, new SystemClock, new PlainSubjectCodec);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
    }

    public function testWhatTheCodecWritesIsTextSafeSoACharacterColumnIsEnough(): void
    {
        // The contract on SubjectCodec::encode() is what lets the schema stay
        // textual — and stay off BLOB's 64 KiB ceiling on MySQL.
        $stored = (new PlainSubjectCodec)->encode(new SubjectWithSecrets('cust-42', 1999, "\xff\xfe\x00raw"));

        self::assertTrue(\mb_check_encoding($stored, 'UTF-8'), 'must be valid UTF-8');
        self::assertStringNotContainsString("\0", $stored, 'must contain no NUL bytes');
    }

    public function testTheMigrationCreatesEveryColumnTheRepositoryUses(): void
    {
        $columns = $this->connection->getSchemaBuilder()->getColumnListing('sagas');
        sort($columns);

        self::assertSame([
            'created_at', 'history', 'id', 'marking', 'subject', 'updated_at', 'version',
        ], $columns);
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

        $this->expectException(SagaConcurrencyException::class);
        $this->expectExceptionMessage('was advanced by another worker');
        $this->repository->save(new SagaState('s1', ['c' => 1], new TestSubject, ['t'], 2));
    }

    public function testConcurrencyExceptionIsASagaException(): void
    {
        $this->repository->save(new SagaState('s1', ['a' => 1], new TestSubject, [], 1));
        $this->repository->save(new SagaState('s1', ['b' => 1], new TestSubject, ['t'], 2));

        try {
            $this->repository->save(new SagaState('s1', ['c' => 1], new TestSubject, ['t'], 2));
            self::fail('expected SagaConcurrencyException');
        } catch (SagaConcurrencyException $e) {
            // Callers catching the library's base type must still see it.
            self::assertInstanceOf(SagaException::class, $e);
        }
    }

    public function testSavingOntoADeletedRowReportsTheStateVanishedNotAStaleVersion(): void
    {
        // A sibling branch deleted the row (terminal completion, or compensation)
        // while this worker was mid-step. Reporting "another worker won the race
        // at version N" would be a lie — there is no row to have raced with.
        $this->repository->save(new SagaState('s1', ['a' => 1], new TestSubject, [], 1));
        $this->repository->delete('s1');

        $this->expectException(SagaConcurrencyException::class);
        $this->expectExceptionMessage('no longer exists');
        $this->repository->save(new SagaState('s1', ['b' => 1], new TestSubject, ['t'], 2));
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

    // ───────── subject bytes: non-public properties and binary payloads ─────────

    public function testASubjectWithNonPublicPropertiesAndBinaryDataRoundTripsByteExact(): void
    {
        // serialize() encodes non-public property names with NUL bytes
        // (\0*\0prop for protected, \0Class\0prop for private), which is how
        // most people write a value object, and a character column is not
        // byte-safe: PostgreSQL truncates at the first NUL without erroring,
        // MySQL in strict mode rejects the row, MariaDB substitutes bytes. The
        // codec is what keeps those bytes out of the column, so this holds with
        // an ordinary longtext.
        $subject = new SubjectWithSecrets('cust-42', 1999, \gzcompress(\str_repeat('payload ', 40)));

        $this->repository->save(new SagaState('bin-1', ['start' => 1], $subject, [], 1));

        $loaded = $this->repository->load('bin-1');
        self::assertInstanceOf(SubjectWithSecrets::class, $loaded?->subject);
        self::assertSame($subject->describe(), $loaded->subject->describe());
        self::assertSame($subject->blob, $loaded->subject->blob, 'binary payload must survive byte-for-byte');
    }

    public function testMarkingTokenCountsRoundTrip(): void
    {
        $this->repository->save(new SagaState('rt-1', ['pool' => 3, 'other' => 1], new TestSubject, ['t1'], 1));

        $loaded = $this->repository->load('rt-1');
        self::assertSame(['pool' => 3, 'other' => 1], $loaded?->marking);
        self::assertSame(['t1'], $loaded->history);
    }

    // ───────────────── corrupt rows are reported, not repaired ─────────────────

    public function testAMarkingThatIsNotAMapIsRejected(): void
    {
        // decodeJson() used to turn any non-array JSON into [], and an empty
        // marking makes Symfony restart the saga from its initial places.
        $this->corrupt('bad-1', ['marking' => '"start"']);

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('bad-1');
        $this->repository->load('bad-1');
    }

    public function testAMarkingWithNonIntegerTokenCountsIsRejected(): void
    {
        $this->corrupt('bad-2', ['marking' => '{"a":"lots"}']);

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('bad-2');
        $this->repository->load('bad-2');
    }

    public function testAMarkingStoredAsAJsonListIsRejected(): void
    {
        // array_keys() would yield ints, and Marking::mark(string) then raises a
        // TypeError under strict_types that catch (SagaException) cannot see.
        $this->corrupt('bad-3', ['marking' => '["a","b"]']);

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('bad-3');
        $this->repository->load('bad-3');
    }

    public function testAHistoryContainingNonStringsIsRejected(): void
    {
        // compensateAndDelete() interpolates history entries straight into event
        // names, so anyone able to write this column chose which listeners ran.
        $this->corrupt('bad-4', ['history' => '[{"evil":1}]']);

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('bad-4');
        $this->repository->load('bad-4');
    }

    // ───────────────── database errors must not leak the subject ─────────────────

    public function testADuplicateStartRaisesATypedErrorWithoutLeakingTheSubject(): void
    {
        // QueryException::formatMessage() substitutes every binding unredacted,
        // and the binding here is the whole business object. A redelivered
        // webhook — start() twice for one id — is enough to copy a customer
        // reference and a live API token into laravel.log and the error tracker.
        $subject = new SubjectWithSecrets('cust-42', 1999, 'sk_live_DEADBEEFCAFE');

        $this->repository->save(new SagaState('dup-1', ['a' => 1], $subject, [], 1));

        try {
            $this->repository->save(new SagaState('dup-1', ['a' => 1], $subject, [], 1));
            self::fail('a duplicate insert must be reported');
        } catch (SagaException $e) {
            self::assertStringContainsString('dup-1', $e->getMessage());

            // getPrevious() must not smuggle the payload back out either: every
            // handler that walks the chain would print it.
            $chain = $e;
            while ($chain !== null) {
                self::assertStringNotContainsString('sk_live_DEADBEEFCAFE', $chain->getMessage());
                self::assertStringNotContainsString('cust-42', $chain->getMessage());
                $chain = $chain->getPrevious();
            }
        }
    }

    /** @param array<string, string> $columns */
    private function corrupt(string $id, array $columns): void
    {
        $this->repository->save(new SagaState($id, ['a' => 1], new TestSubject, [], 1));
        $this->connection->table('sagas')->where('id', $id)->update($columns);
    }
}
