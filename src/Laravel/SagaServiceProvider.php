<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel;

use Carbon\FactoryImmutable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Workflow\Registry;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Techork\Saga\SagaMarkingStore;
use Techork\Saga\SagaQueue;
use Techork\Saga\SagaRunner;
use Techork\Saga\SagaStateRepository;

/**
 * Default bindings for the Laravel integration.
 *
 * Defaults:
 *   - SagaStateRepository      -> DatabaseSagaStateRepository on the default
 *                                 DB connection, table `sagas`
 *   - SagaQueue                -> LaravelSagaQueue using the default
 *                                 connection and queue
 *   - EventDispatcherInterface -> LaravelEventDispatcherAdapter (bridges to
 *                                 Laravel's native event dispatcher)
 *   - SagaMarkingStore         -> request-scoped WeakMap store
 *   - Registry                 -> empty Symfony {@see Registry}; the
 *                                 application registers workflows against it
 *                                 at boot time
 *   - SagaRunner               -> composed from the bindings above
 *
 * Publish the migration:
 *     php artisan vendor:publish --tag=saga-migrations
 */
final class SagaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SagaStateRepository::class, static function (Application $app): SagaStateRepository {
            /** @var ConnectionResolverInterface $resolver */
            $resolver = $app->make('db');

            return new DatabaseSagaStateRepository($resolver->connection(), FactoryImmutable::getDefaultInstance());
        });

        $this->app->singleton(SagaQueue::class, static fn (Application $app): SagaQueue => new LaravelSagaQueue(
            $app->make(BusDispatcher::class),
            $app,
        ));

        $this->app->singleton(EventDispatcherInterface::class, static fn (Application $app): EventDispatcherInterface => new LaravelEventDispatcherAdapter(
            $app->make(EventDispatcher::class),
        ));

        $this->app->singleton(SagaMarkingStore::class, static fn (): SagaMarkingStore => new SagaMarkingStore());

        $this->app->singleton(Registry::class, static fn (): Registry => new Registry());

        $this->app->singleton(SagaRunner::class, static fn (Application $app): SagaRunner => new SagaRunner(
            $app->make(SagaStateRepository::class),
            $app->make(SagaQueue::class),
            $app->make(EventDispatcherInterface::class),
            $app->make(Registry::class),
            $app->make(SagaMarkingStore::class),
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
