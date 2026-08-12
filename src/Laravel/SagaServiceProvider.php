<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Workflow\Registry;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Techork\Saga\SagaException;
use Techork\Saga\SagaLocator;
use Techork\Saga\SagaLock;
use Techork\Saga\SagaMarkingStore;
use Techork\Saga\SagaQueue;
use Techork\Saga\SagaRunner;
use Techork\Saga\SagaStateRepository;
use Techork\Saga\SubjectCodec;
use Techork\Saga\SystemClock;

/**
 * Default bindings for the Laravel integration.
 *
 * The binding closures take `Illuminate\Contracts\Container\Container`, not
 * `Foundation\Application`: nothing here needs the framework application, and
 * narrowing it is what makes the provider resolvable — and therefore testable —
 * against a bare container.
 *
 * Defaults:
 *   - SagaStateRepository      -> DatabaseSagaStateRepository on the default
 *                                 DB connection, table `sagas`
 *   - SubjectCodec             -> EncryptedSubjectCodec on the application's
 *                                 encrypter, so a forged subject payload is
 *                                 rejected before it can be deserialized
 *   - SagaQueue                -> LaravelSagaQueue using the default
 *                                 connection and queue
 *   - EventDispatcherInterface -> LaravelEventDispatcherAdapter (bridges to
 *                                 Laravel's native event dispatcher)
 *   - SagaLock                 -> CacheSagaLock on the default cache store,
 *                                 which must support atomic locks
 *   - SagaMarkingStore         -> request-scoped WeakMap store
 *   - Registry                 -> empty Symfony {@see Registry}; the
 *                                 application registers workflows against it
 *                                 at boot time
 *   - SagaLocator              -> ContainerSagaLocator (sagas may take dependencies)
 *   - SagaRunner               -> composed from the bindings above
 *
 * Publish the migration:
 *     php artisan vendor:publish --tag=saga-migrations
 */
final class SagaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SagaStateRepository::class, static function (Container $app): SagaStateRepository {
            /** @var ConnectionResolverInterface $resolver */
            $resolver = $app->make('db');

            return new DatabaseSagaStateRepository(
                $resolver->connection(),
                new SystemClock(),
                $app->make(SubjectCodec::class),
            );
        });

        // Authenticated encryption, not an allow-list: it is the only guard that
        // acts before unserialize() constructs anything. See SubjectCodec.
        $this->app->singleton(
            SubjectCodec::class,
            static fn (Container $app): SubjectCodec => new EncryptedSubjectCodec($app->make(StringEncrypter::class)),
        );

        $this->app->singleton(SagaQueue::class, static fn (Container $app): SagaQueue => new LaravelSagaQueue(
            $app->make(BusDispatcher::class),
            $app,
        ));

        $this->app->singleton(EventDispatcherInterface::class, static fn (Container $app): EventDispatcherInterface => new LaravelEventDispatcherAdapter(
            $app->make(EventDispatcher::class),
        ));

        $this->app->singleton(SagaMarkingStore::class, static fn (): SagaMarkingStore => new SagaMarkingStore());

        $this->app->singleton(Registry::class, static fn (): Registry => new Registry());

        $this->app->singleton(SagaLock::class, static function (Container $app): SagaLock {
            $store = $app->make(CacheFactory::class)->store()->getStore();

            if (! $store instanceof LockProvider) {
                throw new SagaException(\sprintf(
                    'The saga lock needs a cache store with atomic locks, but the default store (%s) has none. '
                    . 'Use redis, memcached, dynamodb, database or array — the file store cannot do this. '
                    . 'Without it, two workers can run the same saga at once and silently lose each other\'s '
                    . 'writes to the subject.',
                    $store::class,
                ));
            }

            return new CacheSagaLock($store);
        });

        $this->app->singleton(
            SagaLocator::class,
            static fn (Container $app): SagaLocator => new ContainerSagaLocator($app),
        );

        $this->app->singleton(SagaRunner::class, static fn (Container $app): SagaRunner => new SagaRunner(
            $app->make(SagaStateRepository::class),
            $app->make(SagaQueue::class),
            $app->make(EventDispatcherInterface::class),
            $app->make(Registry::class),
            $app->make(SagaMarkingStore::class),
            $app->make(SagaLock::class),
            $app->make(SagaLocator::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $stub = __DIR__ . '/../../database/migrations/create_sagas_table.php.stub';
            $this->publishes(
                [$stub => $this->migrationTarget('create_sagas_table.php')],
                'saga-migrations',
            );
        }
    }

    private function migrationTarget(string $filename): string
    {
        $timestamp = \date('Y_m_d_His');

        return $this->app->databasePath("migrations/{$timestamp}_{$filename}");
    }
}
