<?php

declare(strict_types=1);

namespace Techork\Saga;

use RuntimeException;

/**
 * Base exception for the saga library.
 *
 * Raised for operational problems the library detects itself: a definition with
 * no initial places, a definition whose initial marking enables nothing, a
 * corrupt persisted payload, a re-entrant lock acquisition, an encode/decode
 * failure. A missing saga row is NOT one of them — both
 * {@see SagaRunner::run()} and {@see SagaRunner::compensateAndDelete()} return
 * quietly for an id that no longer exists, because signal-driven callers may
 * legitimately race a saga that has just finished.
 *
 * Two subtypes carry meaning worth branching on:
 *  - {@see SagaConcurrencyException} — another worker holds or has advanced the
 *    saga. Retry the step; never compensate.
 *  - {@see SagaFailedException} — a rollback failed and is incomplete.
 *
 * A failure inside a transition's own action is not wrapped at all: it bubbles
 * out of run() as whatever the application threw.
 */
class SagaException extends RuntimeException
{
}
