<?php

declare(strict_types=1);

namespace Techork\Saga;

use function array_key_exists;

/**
 * {@see SagaLock} scoped to one PHP process.
 *
 * Suitable for tests, for synchronous single-process drivers, and for a
 * deployment that is genuinely guaranteed to run one worker. It provides NO
 * protection between processes — pair {@see InMemorySagaStateRepository} with
 * this one, and a shared repository with a shared lock
 * ({@see \Techork\Saga\Laravel\CacheSagaLock}).
 *
 * Re-entering the lock for a saga already held by this process is a bug in the
 * caller — a step driving another step of the same saga inline — so it throws
 * rather than deadlocking or silently allowing it.
 */
final class InProcessSagaLock implements SagaLock
{
    /** @var array<string, true> */
    private array $held = [];

    public function withLock(string $sagaId, callable $work): mixed
    {
        if (array_key_exists($sagaId, $this->held)) {
            throw new SagaException(\sprintf(
                "Re-entrant lock acquisition for saga '%s': a step is already running for it in this process.",
                $sagaId,
            ));
        }

        $this->held[$sagaId] = true;

        try {
            return $work();
        } finally {
            unset($this->held[$sagaId]);
        }
    }
}
