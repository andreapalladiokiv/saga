<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Techork\Saga\SagaConcurrencyException;
use Techork\Saga\SagaLock;

use function max;
use function microtime;
use function usleep;

/**
 * {@see SagaLock} backed by Laravel's atomic cache locks.
 *
 * Deliberately not a database transaction: the lock is held for the whole
 * step, including the user's transition action, and holding an open
 * transaction plus a row lock across a network call would tie up a connection
 * and grow the undo log for as long as that call takes. A cache lock survives
 * being held for seconds and releases itself if the holder dies.
 *
 * REQUIRES a cache store implementing {@see LockProvider} — `redis`,
 * `memcached`, `dynamodb`, `database` or `array`. The `file` store does NOT
 * implement it, and binding this against one raises immediately rather than
 * pretending sagas are protected.
 *
 * Two durations matter and they answer different questions:
 *
 *  - $waitSeconds — how long a worker queues behind the current holder before
 *    giving up. Exceeding it raises {@see SagaConcurrencyException}, which the
 *    queue layer retries; it never compensates.
 *  - $ttlSeconds — how long the lock survives if the holder dies mid-step.
 *    It must exceed the slowest transition, or a second worker will enter
 *    while the first is still running. The optimistic-lock check on save()
 *    remains the backstop for exactly that case. It must also stay below
 *    {@see \Techork\Saga\Laravel\SagaStepJob}'s total retry window, or a
 *    step gives up before a dead holder's lock expires — see the invariant
 *    documented on that class.
 */
final readonly class CacheSagaLock implements SagaLock
{
    public function __construct(
        private LockProvider $store,
        private int $ttlSeconds = 120,
        private int $waitSeconds = 3,
        private string $prefix = 'saga:lock:',
        private int $pollMicroseconds = 100_000,
    ) {}

    public function withLock(string $sagaId, callable $work): mixed
    {
        /** @var Lock $lock */
        $lock = $this->store->lock($this->prefix.$sagaId, $this->ttlSeconds);

        $this->acquire($lock, $sagaId);

        try {
            return $work();
        } finally {
            // Releasing here rather than via Lock::block()'s callback means a
            // step that throws cannot leave the saga locked until the TTL.
            $lock->release();
        }
    }

    /**
     * Polls for the lock until $waitSeconds elapses.
     *
     * Deliberately not {@see Lock::block()}: in some illuminate/cache releases
     * that method calls the global `now()` helper, which ships with
     * laravel/framework rather than with the component this package depends
     * on, so it fatals in a component-only install.
     */
    private function acquire(Lock $lock, string $sagaId): void
    {
        $deadline = microtime(true) + max(0, $this->waitSeconds);

        while (! $lock->get()) {
            if (microtime(true) >= $deadline) {
                throw SagaConcurrencyException::lockTimeout($sagaId, $this->waitSeconds);
            }

            usleep($this->pollMicroseconds);
        }
    }
}
