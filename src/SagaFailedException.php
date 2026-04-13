<?php

declare(strict_types=1);

namespace Techork\Saga;

use Throwable;

/**
 * Raised when a transition's action throws. Compensations for every
 * previously-applied transition have already run by the time this bubbles
 * up; any errors they produced are collected in {@see $compensationErrors}.
 *
 * Catch this (not {@see SagaException}) when you want to react specifically
 * to domain-step failures.
 */
final class SagaFailedException extends SagaException
{
    /** @param Throwable[] $compensationErrors */
    public function __construct(
        string $message,
        public readonly Throwable $cause,
        public readonly array $compensationErrors = [],
    ) {
        parent::__construct($message, 0, $cause);
    }
}
