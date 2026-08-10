<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Encryption\Encrypter;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher as LaravelEvents;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Registry;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Techork\Saga\Laravel\CacheSagaLock;
use Techork\Saga\Laravel\DatabaseSagaStateRepository;
use Techork\Saga\Laravel\EncryptedSubjectCodec;
use Techork\Saga\Laravel\LaravelEventDispatcherAdapter;
use Techork\Saga\Laravel\LaravelSagaQueue;
use Techork\Saga\Laravel\SagaServiceProvider;
use Techork\Saga\SagaException;
use Techork\Saga\SagaLock;
use Techork\Saga\SagaMarkingStore;
use Techork\Saga\SagaQueue;
use Techork\Saga\SagaRunner;
use Techork\Saga\SagaStateRepository;
use Techork\Saga\SubjectCodec;
use Techork\Saga\Tests\Laravel\Fixtures\RecordingBusDispatcher;

/**
 * The provider is the only wiring a real Laravel consumer gets, and it was at
 * 0% coverage — so a binding that fatals on first resolution shipped unnoticed.
 */
final class SagaServiceProviderTest extends TestCase
{
    private Container $app;

    protected function setUp(): void
    {
        $this->app = new Container;
        Container::setInstance($this->app);

        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->app->instance('db', $capsule->getDatabaseManager());
        $this->app->instance(BusDispatcher::class, new RecordingBusDispatcher);
        $this->app->instance(LaravelEvents::class, new LaravelEvents($this->app));

        $this->app->instance(
            StringEncrypter::class,
            new Encrypter(Encrypter::generateKey('aes-256-gcm'), 'aes-256-gcm'),
        );

        $this->bindCacheStore(new ArrayStore);
    }

    protected function tearDown(): void
    {
        Container::setInstance();
    }

    public function testItBindsEveryCollaboratorTheRunnerNeeds(): void
    {
        $this->register();

        self::assertInstanceOf(DatabaseSagaStateRepository::class, $this->app->make(SagaStateRepository::class));
        self::assertInstanceOf(EncryptedSubjectCodec::class, $this->app->make(SubjectCodec::class));
        self::assertInstanceOf(LaravelSagaQueue::class, $this->app->make(SagaQueue::class));
        self::assertInstanceOf(LaravelEventDispatcherAdapter::class, $this->app->make(EventDispatcherInterface::class));
        self::assertInstanceOf(CacheSagaLock::class, $this->app->make(SagaLock::class));
        self::assertInstanceOf(SagaMarkingStore::class, $this->app->make(SagaMarkingStore::class));
        self::assertInstanceOf(Registry::class, $this->app->make(Registry::class));
        self::assertInstanceOf(SagaRunner::class, $this->app->make(SagaRunner::class));
    }

    public function testTheSubjectCodecAuthenticatesRatherThanAllowListing(): void
    {
        // A forged payload must be refused BEFORE unserialize() constructs
        // anything: construction is itself the exploit, so a check on the result
        // is a check after the fact.
        $this->register();

        /** @var SubjectCodec $codec */
        $codec = $this->app->make(SubjectCodec::class);
        $forged = \serialize(new \Techork\Saga\Tests\TestSubject);

        $this->expectException(\Techork\Saga\SagaException::class);
        $this->expectExceptionMessage('did not write');
        $codec->decode($forged, 'forged-1');
    }

    public function testTheRunnerResolvesWithoutTouchingCarbonOrTheAppHelper(): void
    {
        // The provider used to build the repository's clock from
        // Carbon\FactoryImmutable::getDefaultInstance(), which does not exist in
        // Carbon 2 — so on Laravel 10, which the suggest block advertised, the
        // first resolution of SagaRunner fatalled and took every saga with it.
        $this->register();

        self::assertInstanceOf(SagaRunner::class, $this->app->make(SagaRunner::class));
    }

    public function testTheMarkingStoreAndRegistryAreSharedSoWiringCannotDiverge(): void
    {
        // The runner writes the marking into its own store and reads it back;
        // the Workflow instances must hold the SAME object or the saga silently
        // freezes at its initial place.
        $this->register();

        self::assertSame($this->app->make(SagaMarkingStore::class), $this->app->make(SagaMarkingStore::class));
        self::assertSame($this->app->make(Registry::class), $this->app->make(Registry::class));
    }

    public function testACacheStoreWithoutAtomicLocksIsRejectedWithAnExplanation(): void
    {
        // Silently running without mutual exclusion is far worse than refusing
        // to boot: two workers would race one saga and lose each other's writes.
        $this->app->instance(CacheFactory::class, new class implements CacheFactory {
            public function store($name = null): CacheRepositoryContract
            {
                return new CacheRepository(new NonLockingStore);
            }
        });

        $this->register();

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('atomic locks');
        $this->app->make(SagaLock::class);
    }

    private function register(): void
    {
        (new SagaServiceProvider($this->app))->register();
    }

    private function bindCacheStore(ArrayStore $store): void
    {
        $repository = new CacheRepository($store);
        $this->app->instance(CacheFactory::class, new class($repository) implements CacheFactory {
            public function __construct(private CacheRepositoryContract $repository) {}

            public function store($name = null): CacheRepositoryContract
            {
                return $this->repository;
            }
        });
    }
}
