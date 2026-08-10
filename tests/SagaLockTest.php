<?php

declare(strict_types=1);

namespace Techork\Saga\Tests;

use Illuminate\Cache\ArrayStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Techork\Saga\InProcessSagaLock;
use Techork\Saga\Laravel\CacheSagaLock;
use Techork\Saga\SagaConcurrencyException;
use Techork\Saga\SagaException;

final class SagaLockTest extends TestCase
{
    // ───────────────── InProcessSagaLock ─────────────────

    public function testInProcessLockReturnsTheCallablesValue(): void
    {
        self::assertSame(42, (new InProcessSagaLock)->withLock('s1', static fn (): int => 42));
    }

    public function testInProcessLockRejectsReentrantAcquisition(): void
    {
        $lock = new InProcessSagaLock;

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('Re-entrant lock acquisition');

        $lock->withLock('s1', static fn () => $lock->withLock('s1', static fn () => null));
    }

    public function testInProcessLockDoesNotBlockADifferentSaga(): void
    {
        $lock = new InProcessSagaLock;

        $reached = $lock->withLock('s1', static fn () => $lock->withLock('s2', static fn (): string => 'ok'));

        self::assertSame('ok', $reached);
    }

    public function testInProcessLockIsReleasedWhenTheStepThrows(): void
    {
        $lock = new InProcessSagaLock;

        try {
            $lock->withLock('s1', static function (): void {
                throw new RuntimeException('step blew up');
            });
        } catch (RuntimeException) {
            // expected
        }

        // A failed step must not leave the saga permanently locked.
        self::assertSame('free', $lock->withLock('s1', static fn (): string => 'free'));
    }

    // ───────────────── CacheSagaLock ─────────────────

    public function testCacheLockSerialisesTwoWorkersOnTheSameSaga(): void
    {
        // One shared store, two lock instances — two workers.
        $store = new ArrayStore;
        $workerA = new CacheSagaLock($store, ttlSeconds: 60, waitSeconds: 0);
        $workerB = new CacheSagaLock($store, ttlSeconds: 60, waitSeconds: 0);

        $bGotIn = false;

        $workerA->withLock('ord-1', function () use ($workerB, &$bGotIn): void {
            // Worker B arrives while A is mid-step. It must not get in.
            try {
                $workerB->withLock('ord-1', function () use (&$bGotIn): void {
                    $bGotIn = true;
                });
                self::fail('worker B entered a saga another worker was running');
            } catch (SagaConcurrencyException $e) {
                self::assertStringContainsString('waiting for the lock', $e->getMessage());
            }
        });

        self::assertFalse($bGotIn);
    }

    public function testCacheLockDoesNotSerialiseDifferentSagas(): void
    {
        $store = new ArrayStore;
        $workerA = new CacheSagaLock($store, ttlSeconds: 60, waitSeconds: 0);
        $workerB = new CacheSagaLock($store, ttlSeconds: 60, waitSeconds: 0);

        $reached = $workerA->withLock(
            'ord-1',
            static fn () => $workerB->withLock('ord-2', static fn (): string => 'ok'),
        );

        self::assertSame('ok', $reached, 'distinct sagas must stay independent');
    }

    public function testCacheLockIsReleasedAfterTheStepSoTheNextOneCanRun(): void
    {
        $store = new ArrayStore;
        $lock = new CacheSagaLock($store, ttlSeconds: 60, waitSeconds: 0);

        $lock->withLock('ord-1', static fn (): string => 'first');

        self::assertSame('second', $lock->withLock('ord-1', static fn (): string => 'second'));
    }

    public function testCacheLockIsReleasedWhenTheStepThrows(): void
    {
        $store = new ArrayStore;
        $lock = new CacheSagaLock($store, ttlSeconds: 60, waitSeconds: 0);

        try {
            $lock->withLock('ord-1', static function (): void {
                throw new RuntimeException('gateway timeout');
            });
        } catch (RuntimeException) {
            // expected
        }

        // Without the finally in withLock() the saga would stay locked until
        // the TTL expired — a failed step would freeze it for two minutes.
        self::assertSame('free', $lock->withLock('ord-1', static fn (): string => 'free'));
    }

    public function testLockTimeoutIsAConcurrencyExceptionSoItIsRetriedNotCompensated(): void
    {
        $store = new ArrayStore;
        $holder = new CacheSagaLock($store, ttlSeconds: 60, waitSeconds: 0);
        $waiter = new CacheSagaLock($store, ttlSeconds: 60, waitSeconds: 0);

        $holder->withLock('ord-1', function () use ($waiter): void {
            try {
                $waiter->withLock('ord-1', static fn () => null);
                self::fail('expected a lock timeout');
            } catch (SagaConcurrencyException $e) {
                // SagaStepJob keys off exactly this type to re-dispatch the
                // step instead of letting failed() compensate the saga.
                self::assertInstanceOf(SagaException::class, $e);
            }
        });
    }
}
